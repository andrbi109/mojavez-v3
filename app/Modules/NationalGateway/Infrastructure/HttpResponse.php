<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Infrastructure;

/**
 * Immutable HTTP response DTO.
 */
final readonly class HttpResponse
{
    public function __construct(
        public int $status,
        public string $body,
        /** @var array<string,string> */
        public array $headers,
        public float $elapsedSeconds,
        public ?string $error = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function isClientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    public function isServerError(): bool
    {
        return $this->status >= 500;
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }

    /**
     * Decode JSON body to array. Returns null on parse error.
     *
     * @return array<string,mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
