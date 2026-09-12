<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Assignment submission policy
    |--------------------------------------------------------------------------
    |
    | Established product behavior (preserved):
    |  - Due dates are hard deadlines compared in the business timezone.
    |  - submitted_at is always set server-side; the client can never supply it.
    |  - Graded submissions cannot be overwritten by students (409); a grader
    |    must return the submission first, which makes revision explicit.
    |  - Reopening == grader sets status back to "returned".
    |  - First submitted_at is preserved across revisions; every superseded
    |    revision is snapshotted immutably with its own timestamps.
    |
    | Late grace (safest default: 0 = preserve the hard block):
    |  - Within the grace window past due_date, submissions are accepted and
    |    flagged is_late=true. Beyond it, the 403 past_due block stands.
    |  - Raise ASSIGNMENTS_LATE_GRACE_MINUTES only as a deliberate product
    |    decision; the flagging + history logic already handles it.
    |
    */
    'late_grace_minutes' => (int) env('ASSIGNMENTS_LATE_GRACE_MINUTES', 0),

];
