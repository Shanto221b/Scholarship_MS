<?php
/**
 * Server-side validation rules shared by every form.
 * Each function returns an error message, or '' when the value is valid.
 */

function v_required(string $value, string $label): string
{
    return trim($value) === '' ? "$label is required." : '';
}

function v_name(string $value, string $label = 'Name'): string
{
    $value = trim($value);
    if ($value === '') {
        return "$label is required.";
    }
    if (mb_strlen($value) < 2 || mb_strlen($value) > 100) {
        return "$label must be between 2 and 100 characters.";
    }
    if (!preg_match("/^[\\p{L}][\\p{L} .'\\-]*$/u", $value)) {
        return "$label may only contain letters, spaces, dots, apostrophes and hyphens.";
    }
    return '';
}

function v_username(string $value): string
{
    if ($value === '') {
        return 'Username is required.';
    }
    if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $value)) {
        return 'Username must be 3-20 characters: letters, numbers and underscores only.';
    }
    return '';
}

function v_email(string $value): string
{
    if ($value === '') {
        return 'Email is required.';
    }
    if (strlen($value) > 100 || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }
    return '';
}

function v_password(string $password, string $confirm): string
{
    if ($password === '') {
        return 'Password is required.';
    }
    if (strlen($password) < 6 || strlen($password) > 72) {
        return 'Password must be between 6 and 72 characters.';
    }
    if ($password !== $confirm) {
        return 'Password and Confirm Password do not match.';
    }
    return '';
}

function v_semester(string $value): string
{
    if ($value === '') {
        return 'Semester is required.';
    }
    if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 12) {
        return 'Semester must be a whole number from 1 to 12.';
    }
    return '';
}

/** CGPA: optional unless $required, 0.00 - 4.00, max two decimals. */
function v_cgpa(string $value, bool $required = true): string
{
    if ($value === '') {
        return $required ? 'CGPA is required.' : '';
    }
    if (!preg_match('/^\d(\.\d{1,2})?$/', $value) || (float) $value > 4) {
        return 'CGPA must be a number from 0.00 to 4.00 (up to 2 decimals).';
    }
    return '';
}

function v_text(string $value, string $label, int $max, bool $required = true): string
{
    $value = trim($value);
    if ($value === '') {
        return $required ? "$label is required." : '';
    }
    if (mb_strlen($value) > $max) {
        return "$label must be $max characters or fewer.";
    }
    return '';
}

function v_int_range(string $value, string $label, int $min, int $max): string
{
    if ($value === '') {
        return "$label is required.";
    }
    if (!ctype_digit($value) || (int) $value < $min || (int) $value > $max) {
        return "$label must be a whole number from $min to $max.";
    }
    return '';
}

function v_amount(string $value): string
{
    if ($value === '') {
        return 'Amount is required.';
    }
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $value) || (float) $value <= 0) {
        return 'Amount must be a positive number (up to 2 decimals, max 99,999,999.99).';
    }
    return '';
}

function v_date(string $value, string $label, bool $notPast = false): string
{
    if ($value === '') {
        return "$label is required.";
    }
    $d = DateTime::createFromFormat('Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        return "$label must be a valid date.";
    }
    if ($notPast && $value < date('Y-m-d')) {
        return "$label cannot be in the past.";
    }
    return '';
}

/** Collects non-empty error messages. */
function collect_errors(string ...$messages): array
{
    return array_values(array_filter($messages, fn($m) => $m !== ''));
}

/** Optional Bangladeshi mobile number: 01XXXXXXXXX. */
function v_phone(string $value, string $label = 'Phone'): string
{
    if ($value === '') {
        return '';
    }
    return preg_match('/^01[3-9]\d{8}$/', $value) ? '' : "$label must be an 11-digit mobile number like 01712345678.";
}

/** Optional date of birth: real date, age 15 to 80. */
function v_dob(string $value): string
{
    if ($value === '') {
        return '';
    }
    if ($msg = v_date($value, 'Date of birth')) {
        return $msg;
    }
    $age = (int) date_diff(date_create($value), date_create('today'))->y;
    return ($value > date('Y-m-d') || $age < 15 || $age > 80) ? 'Date of birth must give an age between 15 and 80.' : '';
}

function v_choice(string $value, array $allowed, string $label): string
{
    return ($value === '' || in_array($value, $allowed, true)) ? '' : "Please choose a valid $label.";
}
