<?php

declare(strict_types=1);

namespace Kabuto\Middleware;

use Kabuto\Auth\Gate;
use Kabuto\Auth\PolicyTarget;
use Kabuto\Http\Request;
use Kabuto\Http\Response;

final class PolicyAuthorize
{
    public function __construct(private readonly string $ability, private readonly string|\Closure $modelResolver, private readonly string $parameter = '') {}

    public function __invoke(Request $req, Response $res, callable $next): Response
    {
        if ($req->user() === null) {
            return (new Response())->status(401)->json(['statusCode' => 401, 'error' => 'Unauthorized', 'message' => 'Authentication required.']);
        }
        $model = $this->modelResolver instanceof \Closure
            ? ($this->modelResolver)($req)
            : ($req->get($this->modelResolver) ?? ($this->parameter !== '' ? $req->param($this->parameter) : $req->param('id')));
        $target = $this->modelResolver instanceof \Closure || $this->modelResolver === ''
            ? $model
            : new PolicyTarget($model, $this->modelResolver);
        Gate::forUser($req->user())->authorizeModel($this->ability, $target);
        return $next($req, $res);
    }
}
