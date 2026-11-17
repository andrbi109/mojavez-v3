<?php
declare(strict_types=1);

namespace App\Modules\NationalGateway\Infrastructure\Parser;

/**
 * Parser interface — converts an upstream HTTP response body
 * into a list of License domain objects + total count.
 *
 * Strategy interface so that if the upstream site changes its API format,
 * we can swap parsers without touching the rest of the codebase.
 */
interface ResponseParserInterface
{
    /**
     * Parse a search response body.
     *
     * @return array{licenses:list<array<string,mixed>>,total:?int}
     *     licenses: array of raw upstream rows (caller will hydrate)
     *     total: total count if present in this response, otherwise null
     */
    public function parseSearch(string $body): array;

    /**
     * Parse a count response body.
     */
    public function parseCount(string $body): ?int;

    /**
     * Parse a provinces lookup response.
     *
     * @return list<array{id:string,title:string}>
     */
    public function parseProvinces(string $body): array;

    /**
     * Parse townships-for-province response (from /lookups/provinces/{id}/townships).
     *
     * @return list<array{id:string,title:string}>
     */
    public function parseTownships(string $body): array;

    /**
     * Parse an organizations lookup response.
     *
     * @return array{main:list<array{id:string,title:string}>,sub:list<array{id:string,title:string}>}
     */
    public function parseOrganizations(string $body): array;

    /**
     * Parse sub-organizations-for-main-org response (from /lookups/organizations/{id}/subs).
     *
     * @return list<array{id:string,title:string}>
     */
    public function parseSubOrganizations(string $body): array;
}
