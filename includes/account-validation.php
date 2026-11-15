<?php
/**
 * Role constants and account-field validation shared by the web dashboard and the CLI utilities.
 * Pure functions: no session, no database and no output, so the web and CLI paths cannot drift apart.
 */

/** Roles that may use the admin console. The legacy 'organizer' value is deliberately absent: migration 009
 *  converts every organizer to event_admin, and any leftover legacy row fails closed. */
const FESTORA_ADMIN_ROLES = ['event_admin', 'super_admin'];
/** Roles that may hold an authenticated session. */
const FESTORA_ACCOUNT_ROLES = ['student', 'event_admin', 'super_admin'];
/** bcrypt ignores everything after 72 bytes, so longer passwords would be silently truncated. */
const FESTORA_ADMIN_PASSWORD_MIN = 12;
const FESTORA_ADMIN_PASSWORD_MAX = 72;

function is_admin_role($role): bool {
    return is_string($role) && in_array($role, FESTORA_ADMIN_ROLES, true);
}

function is_super_admin_role($role): bool {
    return $role === 'super_admin';
}

function festora_normalize_email(string $email): string {
    return strtolower(trim($email));
}

/**
 * True for demo-account addresses (the reserved @festora.test domain). Lives here, next to the other
 * pure account helpers, so the web bootstrap and CLI utilities such as scripts/provision-admin.php share
 * one definition. Callers decide what to do with it; demo accounts only work when
 * festora_is_development() is true.
 */
function is_demo_email(string $email): bool {
    return str_ends_with(festora_normalize_email($email), '@festora.test');
}

/** @return list<string> */
function festora_account_field_errors(string $name, string $email): array {
    $errors = [];
    $length = mb_strlen($name);
    if ($length < 2 || $length > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
        $errors[] = 'Name must be between 2 and 100 characters.';
    }
    if (strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Enter a valid email address of at most 150 characters.';
    }
    return $errors;
}

/** @return list<string> */
function festora_password_policy_errors(string $password, string $email = ''): array {
    $errors = [];
    $bytes = strlen($password);
    if ($bytes < FESTORA_ADMIN_PASSWORD_MIN || $bytes > FESTORA_ADMIN_PASSWORD_MAX) {
        $errors[] = 'Password must be between ' . FESTORA_ADMIN_PASSWORD_MIN . ' and '
            . FESTORA_ADMIN_PASSWORD_MAX . ' characters.';
    } elseif (str_contains($password, "\0")) {
        $errors[] = 'Password contains an unsupported character.';
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $errors[] = 'Password must contain at least one letter and one digit.';
    } elseif ($email !== '' && strcasecmp($password, $email) === 0) {
        $errors[] = 'Password must not be the same as the email address.';
    }
    return $errors;
}
