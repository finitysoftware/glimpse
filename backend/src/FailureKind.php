<?php

declare(strict_types=1);

namespace Glimpse;

/** What kind of failure stopped a request; the HTTP layer (Http\ErrorHandler) turns it into a status code. */
enum FailureKind
{
    /** The request itself is malformed: a missing field, a bad page number, an invalid name. */
    case Invalid;
    /** Nobody is signed in, the session ended, or the credentials are wrong. */
    case Unauthenticated;
    /** Signed in, but not allowed to do this. */
    case Forbidden;
    /** Missing, or indistinguishable from missing for this user. */
    case NotFound;
    /** The state changed in the meantime or does not allow this yet: reload and try again. */
    case Conflict;
    /** Understood, but refused by a rule: a wrong current password, a token without access, a file too large to show. */
    case Rejected;
    /** Too many attempts or requests; the failure says when to try again. */
    case RateLimited;
    /** GitHub or GitLab refused, failed or answered in a way Glimpse cannot use. */
    case Upstream;
    /** Glimpse cannot serve this now: not configured, busy or being updated. */
    case Unavailable;
}
