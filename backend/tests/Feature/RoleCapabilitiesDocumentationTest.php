<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CrmCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 — documentation consistency.
 *
 * ROLE_CAPABILITIES.md is the authoritative capability documentation for the
 * current implementation. These tests parse that artifact and assert it agrees
 * with the code. They are NOT permission-implementation tests: they introduce
 * no authorization behaviour and grant nothing.
 *
 * Their purpose is to make documentation drift detectable. If a role, gate,
 * route count or CRM tier changes in code, the corresponding assertion fails
 * until ROLE_CAPABILITIES.md is updated — so the document can never quietly
 * start lying about the security model.
 */
class RoleCapabilitiesDocumentationTest extends TestCase
{
    use RefreshDatabase;

    private const DOC = __DIR__.'/../../../ROLE_CAPABILITIES.md';

    /** Roles the specification enumerates but that are not provisioned. */
    private const RESERVED_ROLES = ['instructor'];

    /**
 * Resolve a route's gathered middleware to fully-qualified class names.
 *
 * Routes are declared with middleware *aliases* ('admin', 'crm', ...), so
 * gatherMiddleware() yields alias keys rather than class names. Both the alias
 * key and the resolved class are considered, so a route is attributed to a
 * guard whether it was written as `->middleware('admin')` or as the class.
 *
 * @return list<string>
 */
private function resolvedMiddleware(\Illuminate\Routing\Route $route): array
{
    $aliases = app('router')->getMiddleware();

    return array_map(
        static function ($m) use ($aliases) {
            $m = (string) $m;
            if (isset($aliases[$m])) {
                return $m.'|'.$aliases[$m];
            }

            return $m;
        },
        $route->gatherMiddleware()
    );
}

/**
 * Count API routes guarded by each middleware class.
 *
 * @param  array<string, int>  $expected  class => documented count
 * @return array<string, int>
 */
private function countGuardedRoutes(array $expected): array
{
    $counts = array_fill_keys(array_keys($expected), 0);

    foreach (app('router')->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }

        foreach ($this->resolvedMiddleware($route) as $m) {
            foreach ($expected as $class => $_) {
                if (str_contains($m, $class)) {
                    $counts[$class]++;
                    break;
                }
            }
        }
    }

    return $counts;
}

/**
 * Parse the Enforcement Footprint table (§2): alias => documented route count.
 *
 * @return array<string, int>
 */
private function documentedRouteCounts(): array
{
    $counts = [];
    $inTable = false;

    foreach (explode("\n", $this->doc()) as $line) {
        $trimmed = trim($line);

        if (str_starts_with($trimmed, '| Alias | Middleware |')) {
            $inTable = true;

            continue;
        }
        if ($inTable) {
            if (! str_starts_with($trimmed, '|')) {
                break;   // left the table
            }
            if (str_contains($trimmed, '---')) {
                continue;
            }

            $cells = array_map(fn ($c) => $this->clean($c), explode('|', trim($trimmed, '|')));
            if (count($cells) < 3 || ! ctype_digit($cells[2])) {
                continue;   // role-guarded total / authenticated-only summary rows
            }

            $counts[$cells[0]] = (int) $cells[2];
        }
    }

    return $counts;
}

private function doc(): string
    {
        $path = realpath(self::DOC);
        $this->assertNotFalse($path, 'ROLE_CAPABILITIES.md must exist at the repository root');
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, 'ROLE_CAPABILITIES.md must be readable');

        return $contents;
    }

    /**
     * Document text with inline markdown emphasis stripped.
     *
     * Content assertions must not depend on backticks or bold markers, otherwise
     * a purely cosmetic reformat of the document would fail the suite.
     */
    private function plainDoc(): string
    {
        return str_replace(['`', '**', '*'], '', $this->doc());
    }

    /**
     * Strip inline markdown emphasis from a table cell.
     */
    private function clean(string $value): string
    {
        return trim(str_replace(['`', '**', '*'], '', $value));
    }

    /**
     * Parse the Canonical Role Registry table (§1).
     *
     * @return array<string, array{status: string, group: string, portal: string}>
     */
    private function registry(): array
    {
        $rows = [];
        $inTable = false;

        foreach (explode("\n", $this->doc()) as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '| Role | Status |')) {
                $inTable = true;

                continue;
            }
            if ($inTable) {
                if (! str_starts_with($trimmed, '|')) {
                    break;   // left the table
                }
                if (str_contains($trimmed, '---')) {
                    continue;   // separator row
                }

                $cells = array_map('trim', explode('|', trim($trimmed, '|')));
                if (count($cells) < 5) {
                    continue;
                }

                $role = $this->clean($cells[0]);
                $rows[$role] = [
                    'status' => $this->clean($cells[1]),
                    'group' => $this->clean($cells[2]),
                    'portal' => $this->clean($cells[3]),
                ];
            }
        }

        $this->assertNotEmpty($rows, 'the Canonical Role Registry table could not be parsed');

        return $rows;
    }

    // -----------------------------------------------------------------
    // Vocabulary coverage
    // -----------------------------------------------------------------

    public function test_documented_active_roles_correspond_to_the_role_vocabulary(): void
    {
        $registry = $this->registry();

        foreach (User::ROLES as $role) {
            $this->assertArrayHasKey(
                $role,
                $registry,
                "role [{$role}] exists in User::ROLES but is absent from ROLE_CAPABILITIES.md"
            );
        }

        $this->assertCount(
            count(User::ROLES),
            $registry,
            'the registry must document exactly the roles in User::ROLES — no more, no fewer'
        );
    }

    public function test_no_documented_role_is_missing_from_the_vocabulary(): void
    {
        foreach (array_keys($this->registry()) as $role) {
            $this->assertContains(
                $role,
                User::ROLES,
                "ROLE_CAPABILITIES.md documents [{$role}], which is not in User::ROLES"
            );
        }
    }

    public function test_no_role_is_silently_omitted_without_an_explicit_status(): void
    {
        foreach ($this->registry() as $role => $entry) {
            $this->assertNotSame(
                '',
                $entry['status'],
                "role [{$role}] has an empty status; every role needs an explicit one"
            );
            $this->assertMatchesRegularExpression(
                '/CURRENTLY ENFORCED|RESERVED|NOT AVAILABLE|TARGET/',
                $entry['status'],
                "role [{$role}] must declare one of the documented status markers"
            );
        }
    }

    public function test_every_vocabulary_role_is_either_enforced_or_explicitly_reserved(): void
    {
        foreach ($this->registry() as $role => $entry) {
            if (in_array($role, self::RESERVED_ROLES, true)) {
                continue;
            }

            $this->assertStringContainsString(
                'CURRENTLY ENFORCED',
                $entry['status'],
                "role [{$role}] is neither reserved nor marked CURRENTLY ENFORCED"
            );
        }
    }

    // -----------------------------------------------------------------
    // A2 — instructor is RESERVED / UNPROVISIONED
    // -----------------------------------------------------------------

    public function test_instructor_is_documented_as_reserved_and_unprovisioned(): void
    {
        $registry = $this->registry();

        $this->assertArrayHasKey('instructor', $registry);
        $this->assertStringContainsString(
            'RESERVED',
            $registry['instructor']['status'],
            'instructor must be documented RESERVED / UNPROVISIONED'
        );

        // It must not claim a portal.
        $this->assertSame('none', $registry['instructor']['portal']);

        // It must not be attributed to a spec group.
        $this->assertStringContainsString(
            'reserved',
            strtolower($registry['instructor']['group']),
            'instructor must not be silently merged into TUTOR / FACULTY or any other group'
        );
    }

    public function test_instructor_is_granted_no_capability_surface(): void
    {
        // Reserved in documentation, and genuinely unprovisioned in code.
        $instructor = new User();
        $instructor->forceFill(['role' => 'instructor']);

        $this->assertFalse($instructor->isAdmin());
        $this->assertFalse($instructor->isTutor());
        $this->assertFalse($instructor->isPlacementAdvisor());
        $this->assertFalse($instructor->isCompany());
        $this->assertFalse($instructor->canAccessCrm());
        $this->assertFalse($instructor->canOperatePlacement());
        $this->assertFalse($instructor->hasScopedCrmAccess());
        $this->assertFalse($instructor->isSuperAdmin());

        // No role middleware accepts it.
        $this->assertStringNotContainsString(
            'instructor',
            (string) file_get_contents(app_path('Http/Middleware/EnsureUserIsTutorOrAdmin.php'))
        );
    }

    public function test_instructor_remains_in_the_vocabulary(): void
    {
        $this->assertContains('instructor', User::ROLES);
        $this->assertContains('instructor', User::ASSIGNABLE_ROLES);
    }

    // -----------------------------------------------------------------
    // CRM: current-state documentation must match CrmCapabilities
    // -----------------------------------------------------------------

    public function test_crm_current_state_documentation_matches_crm_capabilities(): void
    {
        $doc = $this->plainDoc();

        // The three frontline roles must be documented as one shared tier.
        $scoped = CrmCapabilities::scopedCapabilities();
        $matrix = CrmCapabilities::matrix();

        foreach (['counsellor', 'telecaller', 'course_advisor'] as $role) {
            $this->assertSame(
                $scoped,
                $matrix[$role] ?? null,
                "{$role} must hold the shared scoped tier that the document describes"
            );
        }

        // The document must state the shared-tier reality explicitly.
        $this->assertStringContainsString(
            'one shared scoped tier',
            $doc,
            'ROLE_CAPABILITIES.md must document that the three CRM roles share one scoped tier'
        );
    }

    public function test_target_crm_intent_is_explicitly_marked_not_yet_enforced(): void
    {
        $doc = $this->plainDoc();

        $this->assertStringContainsString(
            'TARGET / BUSINESS INTENT — NOT YET ENFORCED',
            $doc,
            'the target CRM section must be labelled as not enforced'
        );

        // Each of the three target intents must be flagged as not enforced.
        foreach ([
            'CRM lead contact and follow-up operations',
            'CRM counselling / admission operations',
            'Course recommendation, admissions and conversion',
        ] as $intent) {
            $this->assertStringContainsString(
                $intent,
                $doc,
                "target intent [{$intent}] must be documented"
            );
        }

        // The target table must mark every intent as not enforced today.
        $this->assertMatchesRegularExpression(
            '/telecaller.*lead contact.*\s\|\s*NO\s\|/s',
            $doc,
            'the telecaller target row must be marked as not enforced'
        );
        $this->assertStringContainsString(
            'This is not implemented.',
            $doc,
            'the document must state plainly that the target CRM split is not implemented'
        );
    }

    public function test_crm_scoped_roles_are_not_platform_administrators(): void
    {
        foreach (['counsellor', 'telecaller', 'course_advisor'] as $role) {
            $user = new User();
            $user->forceFill(['role' => $role]);

            $this->assertTrue($user->canAccessCrm(), "{$role} must retain CRM access");
            $this->assertFalse(
                $user->isAdmin(),
                "{$role} must NOT be a platform administrator"
            );
            $this->assertFalse(
                $user->canOperatePlacement(),
                "{$role} must NOT reach placement operations"
            );
        }
    }

    // -----------------------------------------------------------------
    // Placement: documentation must match placement authorization
    // -----------------------------------------------------------------

    public function test_placement_advisor_documentation_matches_placement_authorization(): void
    {
        $advisor = new User();
        $advisor->forceFill(['role' => 'placement_advisor']);

        // Documented as placement-operational, not administrative.
        $this->assertTrue($advisor->canOperatePlacement());
        $this->assertTrue($advisor->hasPlacementAccess());
        $this->assertFalse($advisor->isAdmin(), 'placement_advisor must not be an administrator');
        $this->assertFalse($advisor->canAccessCrm(), 'placement_advisor must not hold CRM access');

        $this->assertSame(['placement_advisor'], User::PLACEMENT_ROLES);

        $doc = $this->plainDoc();
        $this->assertStringContainsString(
            'EnsureUserIsPlacementStaff',
            $doc,
            'the document must cite the dedicated placement guard'
        );
        $this->assertStringContainsString(
            '44 routes',
            $doc,
            'the document must state the measured placement route surface'
        );
    }

    // -----------------------------------------------------------------
    // Company: documentation must match the company guard
    // -----------------------------------------------------------------

    public function test_company_documentation_matches_the_company_guard(): void
    {
        $this->assertSame(['company', 'recruiter'], User::CORPORATE_ROLES);

        foreach (User::CORPORATE_ROLES as $role) {
            $user = new User();
            $user->forceFill(['role' => $role]);

            $this->assertTrue($user->isCompany(), "{$role} must be recognised as a corporate role");
            $this->assertFalse($user->canOperatePlacement(), "{$role} must not approve its own vacancy");
            $this->assertFalse($user->canAccessCrm(), "{$role} must not reach the CRM");
        }

        $doc = $this->plainDoc();
        $this->assertStringContainsString(
            'EnsureUserIsCompany',
            $doc,
            'the document must cite the company guard'
        );
        $this->assertStringContainsString(
            '13 routes',
            $doc,
            'the document must state the measured company route surface'
        );
    }

    // -----------------------------------------------------------------
    // Tutor / faculty
    // -----------------------------------------------------------------

    public function test_tutor_faculty_documentation_matches_the_tutor_guard(): void
    {
        $tutor = new User();
        $tutor->forceFill(['role' => 'tutor']);
        $faculty = new User();
        $faculty->forceFill(['role' => 'faculty']);

        $this->assertTrue($tutor->isTutor());
        $this->assertFalse($faculty->isTutor(), 'isTutor() is documented as narrower than the middleware');
        $this->assertFalse($tutor->isAdmin(), 'tutor must not be a platform administrator');
        $this->assertFalse($faculty->isAdmin(), 'faculty must not be a platform administrator');

        // The documented 9-key permission surface must be real.
        $permissions = User::defaultTutorPermissions();
        $this->assertCount(9, $permissions, 'the document states 9 canonical tutor permissions');

        $doc = $this->plainDoc();
        $this->assertStringContainsString(
            'EnsureUserIsTutorOrAdmin',
            $doc,
            'the document must cite the tutor guard'
        );
        $this->assertStringContainsString(
            '61 routes',
            $doc,
            'the document must state the measured tutor route surface'
        );

        foreach (array_keys($permissions) as $key) {
            $this->assertStringContainsString(
                $key,
                $doc,
                "tutor permission [{$key}] must appear in the documentation"
            );
        }
    }

    // -----------------------------------------------------------------
    // Student
    // -----------------------------------------------------------------

    public function test_student_documentation_matches_actual_student_surfaces(): void
    {
        $student = new User();
        $student->forceFill(['role' => 'student']);

        $this->assertTrue($student->isStudent());
        $this->assertFalse($student->isAdmin());
        $this->assertFalse($student->canAccessCrm());
        $this->assertFalse($student->canOperatePlacement());
        $this->assertFalse($student->isCompany());

        // No role middleware grants a student anything: the portal is guarded by
        // the frontend route guard plus per-resource ownership in controllers.
        foreach ([
            'EnsureUserIsAdmin', 'EnsureUserIsSuperAdmin', 'EnsureUserIsTutorOrAdmin',
            'EnsureUserCanAccessCrm', 'EnsureUserIsCompany', 'EnsureUserIsPlacementStaff',
        ] as $middleware) {
            $this->assertStringNotContainsString(
                "'student'",
                (string) file_get_contents(app_path("Http/Middleware/{$middleware}.php")),
                "{$middleware} must not admit the student role"
            );
        }

        $this->assertStringContainsString(
            'No dedicated role alias accepts student',
            $this->plainDoc(),
            'the document must state that students hold no role alias'
        );
    }

    // -----------------------------------------------------------------
    // Admin / super_admin — must not claim a separate super_admin portal
    // -----------------------------------------------------------------

    public function test_super_admin_documentation_does_not_claim_a_separate_portal(): void
    {
        $doc = $this->plainDoc();
        $registry = $this->registry();

        // super_admin shares the /admin portal.
        $this->assertSame('/admin', $registry['super_admin']['portal']);

        // The document must disclose that the dedicated alias guards 0 routes.
        $this->assertStringContainsString(
            'registered in bootstrap/app.php L24 but applied to 0 routes',
            $doc,
            'the document must disclose that the super_admin alias guards no route'
        );
        $this->assertStringContainsString(
            'no separate super-admin portal or route group',
            $doc,
            'the document must state that no separate super-admin surface exists'
        );

        // And that must be true in the implementation.
        $routes = app('router')->getRoutes();
        $guardedBySuperAdminAlias = 0;
        foreach (app('router')->getRoutes() as $route) {
            foreach ($this->resolvedMiddleware($route) as $m) {
                if (str_contains($m, 'EnsureUserIsSuperAdmin')) {
                    $guardedBySuperAdminAlias++;
                }
            }
        }
        $this->assertSame(
            0,
            $guardedBySuperAdminAlias,
            'the document claims the super_admin alias guards no route'
        );

        // super_admin nevertheless holds the administrative tier.
        $super = new User();
        $super->forceFill(['role' => 'super_admin']);
        $this->assertTrue($super->isAdmin());
        $this->assertTrue($super->isSuperAdmin());
        $this->assertTrue($super->canAccessCrm());
    }

    // -----------------------------------------------------------------
    // Measured route surface quoted by the document
    // -----------------------------------------------------------------

    public function test_documented_route_surface_matches_the_implementation(): void
    {
        $doc = $this->plainDoc();

        $expected = [
            'EnsureUserIsAdmin' => 158,
            'EnsureUserIsTutorOrAdmin' => 61,
            // Phase 4 added two read-only placement routes (interview pipeline,
            // placement audit events), so 44 -> 46.
            'EnsureUserIsPlacementStaff' => 46,
            'EnsureUserCanAccessCrm' => 26,
            'EnsureUserIsCompany' => 13,
            'EnsureUserIsSuperAdmin' => 0,
        ];

        $counts = $this->countGuardedRoutes($expected);

        foreach ($expected as $class => $count) {
            $this->assertSame(
                $count,
                $counts[$class],
                "route count for {$class} changed in code; ROLE_CAPABILITIES.md quotes {$count}"
            );
        }

        // The reverse direction: whatever the document states must equal what the
        // code actually does. Without this the document could quietly start
        // quoting a wrong number while the code stays correct.
        $documented = $this->documentedRouteCounts();

        $this->assertNotEmpty($documented, 'the Enforcement Footprint table could not be parsed');

        $aliasToClass = [
            'admin' => 'EnsureUserIsAdmin',
            'tutor' => 'EnsureUserIsTutorOrAdmin',
            'placement' => 'EnsureUserIsPlacementStaff',
            'crm' => 'EnsureUserCanAccessCrm',
            'company' => 'EnsureUserIsCompany',
            'super_admin' => 'EnsureUserIsSuperAdmin',
        ];

        foreach ($aliasToClass as $alias => $class) {
            $this->assertArrayHasKey(
                $alias,
                $documented,
                "ROLE_CAPABILITIES.md does not document the [{$alias}] route surface"
            );
            $this->assertSame(
                $counts[$class],
                $documented[$alias],
                "ROLE_CAPABILITIES.md claims [{$alias}] has {$documented[$alias]} routes, "
                ."but the implementation guards {$counts[$class]}"
            );
        }

        $apiRoutes = 0;
        foreach (app('router')->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/')) {
                $apiRoutes++;
            }
        }

        // Phase 4 added two read-only placement routes, so 420 -> 422.
        $this->assertSame(
            422,
            $apiRoutes,
            'total API route count changed; ROLE_CAPABILITIES.md quotes 422'
        );
        $this->assertStringContainsString('422', $doc, 'the document quotes the total API route count');
        $this->assertStringContainsString('304', $doc, 'the document quotes the role-guarded total');
        $this->assertStringContainsString('118', $doc, 'the document quotes the authenticated-only total');
    }

    // -----------------------------------------------------------------
    // Documented security boundaries must be real
    // -----------------------------------------------------------------

    public function test_documented_security_boundaries_are_enforced(): void
    {
        $doc = $this->plainDoc();

        foreach ([
            'admin/super_admin vs ordinary roles' => 1,
            'tutor/faculty ≠ platform admin' => 2,
            'placement_advisor ≠ platform admin' => 3,
            'CRM scoped users cannot perform admin-tier CRM operations' => 4,
            'company cannot self-approve' => 5,
            'company cannot self-publish' => 6,
            'company cannot access another company' => 7,
            'unapproved/suspended company cannot use portal' => 8,
            'student sees published placement opportunities only' => 9,
            'super_admin role changes protected' => 10,
            'ordinary users cannot change their own role' => 11,
            'last-admin protection' => 12,
            'role assignment is not mass-assignable' => 13,
        ] as $boundary => $index) {
            $this->assertStringContainsString(
                $boundary,
                $doc,
                "documented security boundary [{$boundary}] is missing"
            );
            $this->assertMatchesRegularExpression(
                "/\|\s*{$index}\s*\|/",
                $doc,
                "boundary [{$boundary}] must be listed as row {$index} of the boundary table"
            );
        }
    }

    public function test_role_assignment_is_not_mass_assignable_as_documented(): void
    {
        // The document asserts role writes require forceFill and that mass
        // assignment silently drops the attribute. Mass assignment is what
        // Eloquent's constructor and fill() perform, so that is what is probed.
        $constructed = new User(['name' => 'Mass Assignment Probe', 'role' => 'admin']);
        $this->assertNull(
            $constructed->getAttribute('role'),
            'role must NOT be mass-assignable via the model constructor, as ROLE_CAPABILITIES.md claims'
        );

        $filled = new User();
        $filled->fill(['name' => 'Fill Probe', 'role' => 'admin']);
        $this->assertNull(
            $filled->getAttribute('role'),
            'role must NOT be mass-assignable via fill()'
        );

        $this->assertStringContainsString(
            'not mass-assignable',
            $this->plainDoc(),
            'the document must state that role assignment is not mass-assignable'
        );
    }

    public function test_placeholders_and_target_intent_are_not_presented_as_current(): void
    {
        $doc = $this->plainDoc();

        // instructor must never appear as granted.
        $this->assertStringContainsString(
            'No active portal',
            $doc,
            'the document must state that instructor has no active portal'
        );

        // The legend must exist so the status markers are defined.
        $this->assertStringContainsString('CURRENTLY ENFORCED', $doc);
        $this->assertStringContainsString('TARGET / BUSINESS INTENT', $doc);
        $this->assertStringContainsString('NOT AVAILABLE', $doc);
        $this->assertStringContainsString('RESERVED / UNPROVISIONED', $doc);
    }
}
