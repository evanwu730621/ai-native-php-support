# Project Guidelines

## Code Style

- Target PHP 8.2 or newer and follow PSR-12.
- Start every PHP file with `declare(strict_types=1);`, except files where PHP syntax makes this impossible.
- Use typed properties, readonly DTOs, constructor promotion, and explicit return types.
- Keep business logic outside Controllers. Controllers should validate HTTP input and delegate to Services.
- Use dependency injection through PHP-DI; do not instantiate application services inside Controllers.

## Architecture

- Use the existing Slim 4, PHP-DI, and `src/` PSR-4 structure.
- Organize application code under:
  - `src/Controller/` for HTTP request and response handling
  - `src/Service/AI/` for provider, prompt, streaming, structured-output, and tool orchestration
  - `src/Service/` for domain-specific application services
  - `src/DTO/` for typed request, response, and event payloads
  - `src/Tool/` for LLM-callable PHP business operations
  - `src/Domain/` for domain contracts and exceptions
  - `src/Infrastructure/` for repositories and external integrations
- Keep AI SDK/provider types behind application interfaces so provider details do not leak into Controllers, DTOs, Domain code, or Tools.
- Model LLM-controlled operations as registered Tools with typed arguments, JSON schemas, authorization checks, and bounded execution rounds.
- LLM output must be decoded and validated before mapping to DTOs; never trust provider JSON.

## AI Streaming

- Stream responses through SSE endpoints using `text/event-stream`.
- Define separate events for tokens, tool calls, tool results, completion, and errors.
- Do not expose provider credentials, internal stack traces, prompts, or raw provider errors in SSE payloads.
- Check request cancellation and enforce provider, tool, and overall execution timeouts.

## Security

- Read secrets only from environment variables or the server secret store.
- Never commit API keys, tokens, credentials, or personal data.
- Validate all HTTP and tool arguments.
- Apply authorization before executing Tools that read or modify protected business data.
- Rate-limit AI endpoints and record correlation IDs for audit logs.

## Build and Test

- Install dependencies with `composer install`.
- Run syntax checks with `php -l`.
- Run coding standards with `composer phpcs`.
- Run static analysis with `composer phpstan`.
- Run tests with `vendor/bin/phpunit`.
- Validate code quality with `composer check` before finishing implementation work.
