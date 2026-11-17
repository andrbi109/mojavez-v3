<?php
/**
 * registration/_dynamic_fields.php — partial: render dynamic form fields.
 *
 * Phase 14 (v1.5.0) — pays D-7-3. The wizard's custom step
 * (`step_custom.php`) renders an arbitrary set of form_fields rows
 * (defined in the admin FormField CRUD). This partial renders one
 * Bootstrap form-group per field.
 *
 * Expected $data:
 *   $fields  array<int, array{field_key, field_label, field_type,
 *           field_options, is_required}>  — the form_fields rows to render.
 *   $values  array<string, string>  — previously-submitted values
 *           (for re-display on validation error).
 *
 * Each field gets a Bootstrap form-control / form-select / form-check
 * depending on field_type. CSRF must be added by the parent form (the
 * step view that includes this partial).
 */
/** @var array<int, array<string,mixed>> $fields */
/** @var array<string, string> $values */
/** @var string $csrfField */

$fields ??= [];
$values ??= [];
?>
<?php foreach ($fields as $f):
    $key   = (string)($f['field_key'] ?? '');
    $label = (string)($f['field_label'] ?? '');
    $type  = (string)($f['field_type'] ?? 'text');
    $req   = !empty($f['is_required']);
    $opts  = is_array($f['field_options'] ?? null) ? $f['field_options'] : [];
    $val   = (string)($values[$key] ?? '');
    $id    = 'fld_' . htmlspecialchars($key, ENT_QUOTES);
?>
    <div class="mb-3">
        <label for="<?= $id ?>" class="form-label">
            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            <?php if ($req): ?><span class="text-danger">*</span><?php endif; ?>
        </label>
        <?php if ($type === 'select' && $opts !== []): ?>
            <select class="form-select" id="<?= $id ?>" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>" <?= $req ? 'required' : '' ?>>
                <option value="">— انتخاب کنید —</option>
                <?php foreach ($opts as $optVal => $optLabel): ?>
                    <option value="<?= htmlspecialchars((string)$optVal, ENT_QUOTES) ?>"
                        <?= $val === (string)$optVal ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string)$optLabel, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php elseif ($type === 'textarea'): ?>
            <textarea class="form-control" id="<?= $id ?>" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>"
                rows="3" <?= $req ? 'required' : '' ?>><?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?></textarea>
        <?php elseif ($type === 'checkbox'): ?>
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="<?= $id ?>"
                    name="<?= htmlspecialchars($key, ENT_QUOTES) ?>" value="1" <?= $val === '1' ? 'checked' : '' ?>>
                <label class="form-check-label" for="<?= $id ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></label>
            </div>
        <?php else: ?>
            <input type="<?= htmlspecialchars($type === 'number' ? 'number' : 'text', ENT_QUOTES) ?>"
                class="form-control" id="<?= $id ?>" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>"
                value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= $req ? 'required' : '' ?>>
        <?php endif; ?>
    </div>
<?php endforeach;
/* end of file — no closing ?> tag per CODING_CONVENTIONS §2.1 */

