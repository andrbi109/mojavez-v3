<?php
declare(strict_types=1);

/**
 * mojavez-v3 — contract test base case.
 *
 * Contract tests verify the bidirectional sync contract (§29.1 Stage 2):
 *   - Payload shape (what fields v3 sends to upstream).
 *   - Idempotency key format (dedup contract).
 *   - integration_log audit fields (direction, operation, outcome, source_of_truth,
 *     conflict_resolution, upstream_updated_at, latency_ms).
 *   - Conflict resolution labels (last_write_wins, source_of_truth values).
 *
 * Unlike unit tests, contract tests are SHAPE tests — they construct payloads
 * via OutboundSyncService against a real DB and verify the resulting
 * integration_log row matches the documented contract. They do NOT test the
 * actual HTTP push (upstream has no writable endpoint yet — §19.8).
 *
 * When upstream adds a writable endpoint, these tests grow to verify the HTTP
 * contract (status codes, response shape, retry semantics). The current tests
 * are the foundation.
 */
