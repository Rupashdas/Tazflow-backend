<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Adds the API routes the Postman collection does not have yet. Requests that
 * are already there (even hand-edited ones) are never changed. Folders are
 * named after the controller, so an emptied collection is rebuilt in the
 * same order as routes/api.php.
 */
class PostmanSync extends Command {
    protected $signature = 'postman:sync
        {--dry-run : Show what would be added, change nothing}
        {--file= : Read the collection from this local file instead of Postman (never pushes)}';

    protected $description = 'Add API routes that are missing from the Postman collection';

    private const API = 'https://api.getpostman.com/collections/';

    // Controllers that share a folder. Any other controller gets its own,
    // named after it in the plural (RoleController → "Roles").
    private const FOLDERS = [
        'CsrfCookie'                    => 'Auth',
        'Auth'                          => 'Auth',
        'PasswordReset'                 => 'Auth',
        'EmailVerificationNotification' => 'Auth',
        'Profile'                       => 'Profile',
        'Preference'                    => 'Profile',
        'Capability'                    => 'Roles',
    ];

    // Request names the "List / Get / Create …" rule would get wrong.
    private const NAMES = [
        'Auth@user'                           => 'Current User',
        'PasswordReset@sendLink'              => 'Send Password Reset Link',
        'PasswordReset@reset'                 => 'Reset Password',
        'EmailVerificationNotification@store' => 'Resend Verification Email',
    ];

    /**
     * Bodies for routes whose controller validates inline (no FormRequest
     * for the generic rule-reading logic in body() to find). Values point
     * at a collection variable wherever one matches, so a freshly synced
     * request already works against the demo seeder.
     */
    private const BODIES = [
        'PasswordReset@sendLink'      => ['email' => '{{email}}'],
        'PasswordReset@reset'         => [
            'token'                 => '{{reset_token}}',
            'email'                 => '{{email}}',
            'password'              => '{{new_password}}',
            'password_confirmation' => '{{new_password}}',
        ],
        'Profile@updatePassword'      => [
            'current_password'     => '{{password}}',
            'password'              => '{{new_password}}',
            'password_confirmation' => '{{new_password}}',
        ],
        'Preference@update'           => [
            'appearance'  => 'dark',
            'timezone'    => 'Asia/Dhaka',
            'week_start'  => 'sunday',
            'time_format' => '12',
        ],
        'Workspace@store'             => ['name' => 'New Workspace'],
        'Workspace@update'            => ['name' => 'Tazko HQ'],
        'Workspace@destroy'           => ['confirm' => '{{workspace_slug}}'],
        'Workspace@transferOwnership' => ['user_id' => '{{member_user_id}}'],
        'Role@store'                  => ['name' => 'reviewer', 'label' => 'Reviewer', 'capabilities' => ['members.view']],
        'Role@update'                 => ['label' => 'Reviewer (renamed)'],
        'Member@updateRole'           => ['role_id' => '{{role_id}}'],
        'Invitation@store'            => ['name' => '{{invite_name}}', 'email' => '{{invite_email}}', 'role_id' => '{{role_id}}'],
        'Invitation@accept'           => [
            'name'                  => '{{invite_name}}',
            'password'              => '{{password}}',
            'password_confirmation' => '{{password}}',
        ],
    ];

    // A route parameter's default value, so `{role}`, `{user}`… already
    // point at something the demo seeder created instead of an empty string.
    private const PATH_VARIABLES = [
        'role'       => '{{role_id}}',
        'user'       => '{{member_user_id}}',
        'invitation' => '{{invitation_id}}',
        'token'      => '{{invite_token}}',
    ];

    /**
     * What DatabaseSeeder always produces after `migrate:fresh --seed`
     * (autoincrement resets, so the ids are fixed): workspace 1 is "tazko",
     * owned by admin@example.com — an owner passes every capability check,
     * so logging in as them can exercise every request in the collection.
     * Role 2 is tazko's "member" role, held by user 4 (Debos), who is not
     * the owner — a valid target for transfer-ownership and member actions.
     */
    private const SEEDED_VARIABLES = [
        'email'          => 'admin@example.com',
        'password'       => 'Pass123#',
        'workspace_slug' => 'tazko',
        'role_id'        => '2',
        'member_user_id' => '4',
        'invite_name'    => 'New Hire',
        'invite_email'   => 'new.hire@example.com',
    ];

    public function handle(): int {
        $local = $this->option('file');

        if (! $local && (! config('services.postman.key') || ! config('services.postman.collection'))) {
            $this->error('Set POSTMAN_API_KEY and POSTMAN_COLLECTION_ID in .env first (or use --file=).');

            return self::FAILURE;
        }

        $collection = $local
            ? json_decode(file_get_contents($local), true, flags: JSON_THROW_ON_ERROR)
            : $this->fetch();

        // Postman leaves `item` out entirely once every folder is deleted.
        $collection['item'] ??= [];

        $this->syncVariables($collection);

        $known  = $this->knownRequests($collection['item']);
        $missing = $this->apiRoutes()->reject(fn (LaravelRoute $route) => isset($known[$this->shape($route->methods()[0], $route->uri())]));

        if ($missing->isEmpty()) {
            $this->info('Already in sync.');

            return self::SUCCESS;
        }

        foreach ($missing as $route) {
            $folder = $this->addRequest($collection, $route);
            $this->line(sprintf('+ %-7s /%-40s →  %s › %s', $route->methods()[0], $route->uri(), $folder, $this->requestName($route)));
        }

        if ($this->option('dry-run') || $local) {
            $this->comment('Nothing was pushed.');

            return self::SUCCESS;
        }

        $this->push($collection);
        $this->info("Pushed {$missing->count()} request(s) to Postman.");

        return self::SUCCESS;
    }

    /**
     * Keeps the handful of variables the demo seeder decides in sync with
     * it. Any other variable (a token picked up at runtime, one a person
     * added by hand) is left exactly as it is.
     */
    private function syncVariables(array &$collection): void {
        $variables = collect($collection['variable'] ?? [])->keyBy('key');

        foreach (self::SEEDED_VARIABLES as $key => $value) {
            $variables[$key] = ['key' => $key, 'value' => $value];
        }

        $collection['variable'] = $variables->values()->all();
    }

    /**
     * Every `api/*` route plus Sanctum's CSRF cookie (needed before login),
     * except signed ones: those are emailed links, not requests you send by hand.
     */
    private function apiRoutes(): Collection {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (LaravelRoute $route) => str_starts_with($route->uri(), 'api/') || $route->uri() === 'sanctum/csrf-cookie')
            ->reject(fn (LaravelRoute $route) => in_array('signed', $route->gatherMiddleware(), true))
            ->values();
    }

    /**
     * "GET api/roles/{}" style keys for what the collection already has, so a
     * route matches whatever id or variable the request happens to use.
     *
     * @return array<string, true>
     */
    private function knownRequests(array $items): array {
        $known = [];

        foreach ($items as $item) {
            if (isset($item['item'])) {
                $known += $this->knownRequests($item['item']);

                continue;
            }

            $url  = $item['request']['url'];
            $raw  = is_array($url) ? ($url['raw'] ?? '') : $url;
            $path = preg_replace('/^\{\{base_url\}\}/', '', explode('?', $raw)[0]);

            $known[$this->shape($item['request']['method'], $path)] = true;
        }

        return $known;
    }

    /** Method plus path with every id / :param / {param} / {{variable}} segment as "{}". */
    private function shape(string $method, string $path): string {
        $segments = array_map(
            fn (string $segment) => preg_match('/^(\d+|:.*|\{.*\})$/', $segment) ? '{}' : $segment,
            explode('/', trim($path, '/')),
        );

        return $method . ' ' . implode('/', $segments);
    }

    /** Puts a new request into its folder (creating the folder if needed) and returns the folder name. */
    private function addRequest(array &$collection, LaravelRoute $route): string {
        $needsWorkspace = in_array('workspace', $route->gatherMiddleware(), true);
        $resource       = $this->resource($route);
        $folder         = $resource ? (self::FOLDERS[$resource] ?? Str::plural(Str::headline($resource))) : 'Unsorted';

        $index = $this->findFolder($collection['item'], $folder);

        if ($index === null) {
            $index = count($collection['item']);
            $collection['item'][] = [
                'name' => $this->nextNumber($collection['item']) . '. ' . $folder,
                'item' => [],
            ];
        }

        $variables = array_column($collection['variable'] ?? [], 'key');
        $collection['item'][$index]['item'][] = $this->buildRequest($route, $needsWorkspace, $variables);

        return $collection['item'][$index]['name'];
    }

    /** "Role" for RoleController, null for a closure route. */
    private function resource(LaravelRoute $route): ?string {
        $controller = $route->getControllerClass();

        return $controller ? Str::before(class_basename($controller), 'Controller') : null;
    }

    /** The top-level folder called `$name`, ignoring its "3. " number and case. */
    private function findFolder(array $items, string $name): ?int {
        foreach ($items as $i => $item) {
            if (isset($item['item']) && Str::lower(preg_replace('/^\d+\.\s*/', '', $item['name'])) === Str::lower($name)) {
                return $i;
            }
        }

        return null;
    }

    private function nextNumber(array $items): int {
        $numbers = array_map(fn (array $item) => (int) $item['name'], $items);

        return ($numbers ? max($numbers) : 0) + 1;
    }

    /** @param list<string> $variables the collection's variable names */
    private function buildRequest(LaravelRoute $route, bool $needsWorkspace, array $variables): array {
        $method = $route->methods()[0];
        $path   = preg_replace('/\{(\w+)\??\}/', ':$1', $route->uri());

        $url = [
            'raw'  => '{{base_url}}/' . $path,
            'host' => ['{{base_url}}'],
            'path' => explode('/', $path),
        ];

        if ($route->parameterNames()) {
            $url['variable'] = array_map(
                fn (string $name) => ['key' => $name, 'value' => self::PATH_VARIABLES[$name] ?? ''],
                $route->parameterNames(),
            );
        }

        $request = [
            'method' => $method,
            'header' => $needsWorkspace ? [['key' => 'X-Workspace', 'value' => '{{workspace_slug}}']] : [],
            'url'    => $url,
        ];

        // DELETE normally carries no body, except a route like /workspace
        // that asks for a typed confirmation — hence the BODIES check too.
        $resource = $this->resource($route);
        $hasOverride = $resource && isset(self::BODIES["{$resource}@{$route->getActionMethod()}"]);

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true) || ($method === 'DELETE' && $hasOverride)) {
            $request['body'] = ['mode' => 'raw', 'raw' => $this->body($route, $variables), 'options' => ['raw' => ['language' => 'json']]];
        }

        return ['name' => $this->requestName($route), 'request' => $request];
    }

    /**
     * A JSON body with one key per field in the route's FormRequest rules.
     * Routes that validate inline get an empty body.
     */
    private function body(LaravelRoute $route, array $variables): string {
        $resource = $this->resource($route);
        $override = $resource ? (self::BODIES["{$resource}@{$route->getActionMethod()}"] ?? null) : null;

        if ($override !== null) {
            return json_encode($override, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        $form = $this->formRequest($route);

        if (! $form) {
            return "{\n}";
        }

        $body = [];

        foreach ((new $form)->rules() as $field => $rules) {
            // "tags.*" means tags is a list.
            if (str_contains($field, '.')) {
                $body[Str::before($field, '.')] = [];

                continue;
            }

            // Rule names only: "max:255" → "max". Rule objects (unique, Password) say nothing about the type.
            $names = collect(is_string($rules) ? explode('|', $rules) : $rules)
                ->filter(fn ($rule) => is_string($rule))
                ->map(fn (string $rule) => Str::before($rule, ':'));

            $value = match (true) {
                in_array($field, $variables, true)                         => '{{' . $field . '}}',
                $names->contains('boolean')                                => false,
                $names->intersect(['integer', 'numeric'])->isNotEmpty()    => 0,
                $names->contains('array')                                  => [],
                default                                                    => '',
            };

            $body[$field] = $value;

            if ($names->contains('confirmed')) {
                $body[$field . '_confirmation'] = $value;
            }
        }

        return json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** The FormRequest class the controller method type-hints, if any. */
    private function formRequest(LaravelRoute $route): ?string {
        if (! $route->getControllerClass()) {
            return null;
        }

        $parameter = collect($route->signatureParameters(['subClass' => FormRequest::class]))->first();

        return $parameter?->getType()->getName();
    }

    /** "List Roles", "Delete Role", "Upload Avatar" … */
    private function requestName(LaravelRoute $route): string {
        $resource = $this->resource($route);

        if (! $resource) {
            return $route->methods()[0] . ' /' . $route->uri();
        }

        $action = $route->getActionMethod();
        $noun   = Str::headline($resource);

        return self::NAMES["{$resource}@{$action}"] ?? match ($action) {
            'index'    => 'List ' . Str::plural($noun),
            'show'     => 'Get ' . $noun,
            'store'    => 'Create ' . $noun,
            'update'   => 'Update ' . $noun,
            'destroy'  => 'Delete ' . $noun,
            '__invoke' => $noun,
            default    => Str::headline($action),
        };
    }

    private function fetch(): array {
        $response = Http::withHeaders(['X-API-Key' => config('services.postman.key')])
            ->acceptJson()
            ->get(self::API . config('services.postman.collection'))
            ->throw();

        return $response->json('collection');
    }

    private function push(array $collection): void {
        // PUT replaces the whole collection, so keep a copy of what Postman had.
        $backup = 'postman-backup/' . now()->format('Ymd-His') . '.json';
        Storage::put($backup, json_encode($this->fetch(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('Backup: storage/app/private/' . $backup);

        Http::withHeaders(['X-API-Key' => config('services.postman.key')])
            ->acceptJson()
            ->put(self::API . config('services.postman.collection'), ['collection' => $collection])
            ->throw();
    }
}
