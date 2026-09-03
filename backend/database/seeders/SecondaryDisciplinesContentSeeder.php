<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\Section;
use Illuminate\Database\Seeder;

class SecondaryDisciplinesContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Elevating Secondary Disciplines to Production-Quality Depth...');

        $targetCategories = [
            'Quality Assurance & Testing',
            'Mobile Engineering',
            'Design & Creative',
            'DATA SCIENCE',
            'DATA ANALYST',
            'Marketing & Business',
            'Healthcare & Life Sciences',
        ];

        $courses = Course::whereIn('category', $targetCategories)
            ->with(['sections.lessons.quiz.questions', 'sections.lessons.assignment'])
            ->get();

        $enhancedLessons = 0;
        $enhancedQuizzes = 0;
        $enhancedAssignments = 0;

        foreach ($courses as $course) {
            foreach ($course->sections as $section) {
                foreach ($section->lessons as $lesson) {
                    // Enrich lesson with domain-specific deep content
                    $richContent = $this->generateDomainSpecificContent($lesson, $section, $course);
                    $meta = $lesson->metadata;
                    if (is_string($meta)) {
                        $meta = json_decode($meta, true) ?: [];
                    } elseif (! is_array($meta)) {
                        $meta = [];
                    }
                    $meta['content'] = $richContent;
                    $lesson->metadata = $meta;
                    $lesson->save();
                    $enhancedLessons++;

                    // Upgrade Quiz with domain-specific questions
                    if ($lesson->type === 'quiz' || $lesson->quiz) {
                        $quiz = $lesson->quiz ?: Quiz::firstOrCreate(
                            ['lesson_id' => $lesson->id],
                            [
                                'title' => $lesson->title . ' Domain Checkpoint',
                                'description' => "Assess your practical mastery of {$section->title}.",
                                'passing_score' => 75,
                                'time_limit' => 20,
                                'is_published' => true,
                            ]
                        );

                        $this->populateDomainQuizQuestions($quiz, $lesson, $section, $course);
                        $enhancedQuizzes++;
                    }

                    // Upgrade Assignment / Capstone with domain-specific project requirements
                    if ($lesson->type === 'assignment' || $lesson->type === 'project' || $lesson->assignment) {
                        $assignment = $lesson->assignment ?: Assignment::firstOrCreate(
                            ['lesson_id' => $lesson->id],
                            [
                                'course_id' => $course->id,
                                'title' => $lesson->title . ' Hands-On Project',
                                'instructions' => "Complete the practical milestone for {$lesson->title}.",
                                'max_marks' => 100,
                                'due_date' => now()->addDays(30),
                                'is_published' => true,
                            ]
                        );

                        $assignment->instructions = $this->generateDomainAssignment($lesson, $section, $course);
                        $assignment->save();
                        $enhancedAssignments++;
                    }
                }
            }
        }

        $this->command->info("Secondary Disciplines Elevation Complete:");
        $this->command->info("- Lessons Enhanced: {$enhancedLessons}");
        $this->command->info("- Quizzes Improved: {$enhancedQuizzes}");
        $this->command->info("- Assignments / Capstones Improved: {$enhancedAssignments}");
    }

    /**
     * Generate domain-specific technical guides tailored to the exact topic.
     */
    private function generateDomainSpecificContent(Lesson $lesson, Section $section, Course $course): string
    {
        $title = $lesson->title;
        $category = $course->category;
        $level = $course->difficulty ?? 'Intermediate';
        $sectionTitle = $section->title;
        $courseTitle = $course->title;

        $codeBlock = $this->getDomainCodeSnippet($title, $category, $level);
        $deepDive = $this->getDomainDeepDive($title, $category, $level, $sectionTitle);
        $pitfalls = $this->getDomainPitfalls($title, $category);
        $practiceTask = $this->getDomainPracticeTask($title, $category, $level);

        return <<<MD
# {$title}

**Specialization**: {$category} • **Level**: {$level} • **Module**: {$sectionTitle}

---

### 🎯 Learning Objectives
By completing this lesson, you will be able to:
- Master the underlying mechanisms, architectural patterns, and execution flow of **{$title}**.
- Write and execute production-grade code, configurations, or design tokens conforming to industry standards.
- Diagnose and resolve common runtime errors, edge cases, performance bottlenecks, and anti-patterns.
- Apply this methodology directly to real-world projects and portfolio submissions.

---

### 📚 Domain-Specific Technical Deep-Dive
{$deepDive}

---

### 💻 Production Code & Implementation Blueprint
The following implementation demonstrates the modern, industry-standard pattern for this topic:

```
{$codeBlock}
```

#### Step-by-Step Implementation Breakdown:
1. **Setup & Initialization**: Import required modules and configure environment fixtures or dependencies.
2. **Execution & Logic Flow**: Execute the core workflow with strict contract validation and error boundaries.
3. **Assertions & Output Verification**: Verify state transitions and assert expected results deterministically.

---

### ⚠️ Common Pitfalls & Anti-Patterns
{$pitfalls}

---

### 🔑 Key Takeaways
- **Deterministic Design**: Ensure operations are idempotent, testable, and maintainable under production loads.
- **Resilience**: Handle asynchronous state, network latency, and edge cases gracefully.
- **Modularity**: Separate concerns cleanly between presentation, business logic, and persistence layers.

---

### 🛠️ Hands-On Practice Task
{$practiceTask}
MD;
    }

    /**
     * Return authentic code snippets for Playwright, React Native, UI/UX, Pandas/NumPy, etc.
     */
    private function getDomainCodeSnippet(string $title, string $category, string $level): string
    {
        $lowerTitle = strtolower($title);
        $lowerCat = strtolower($category);

        // 1. Software Testing / Playwright
        if (str_contains($lowerCat, 'testing') || str_contains($lowerCat, 'quality') || str_contains($lowerTitle, 'test') || str_contains($lowerTitle, 'playwright')) {
            return <<<'CODE'
import { test, expect, Page } from '@playwright/test';

// Page Object Model (POM) Implementation
export class LoginPage {
    constructor(private page: Page) {}

    async navigate() {
        await this.page.goto('http://localhost:5173/login');
        await expect(this.page.getByRole('heading', { name: /student portal/i })).toBeVisible();
    }

    async login(email: string, pass: string) {
        await this.page.getByLabel(/email address/i).fill(email);
        await this.page.getByLabel(/password/i).fill(pass);
        await this.page.getByRole('button', { name: /sign in/i }).click();
    }
}

// End-to-End Test Suite with Network Mocking & Storage State
test.describe('Authentication & Dashboard Flow', () => {
    test('should authenticate student and navigate to active course with auto-waiting', async ({ page }) => {
        const loginPage = new LoginPage(page);
        await loginPage.navigate();
        await loginPage.login('student@example.com', 'password');

        // Assert URL and reactive dashboard stats
        await expect(page).toHaveURL(/.*\/student/);
        const statsPill = page.getByText(/enrolled courses/i);
        await expect(statsPill).toBeVisible({ timeout: 10000 });

        // Assert API payload via response interception
        const responsePromise = page.waitForResponse(res => res.url().includes('/api/student/dashboard') && res.status() === 200);
        await expect(responsePromise).resolves.toBeDefined();
    });
});
CODE;
        }

        // 2. Mobile Engineering / React Native
        if (str_contains($lowerCat, 'mobile') || str_contains($lowerTitle, 'mobile') || str_contains($lowerTitle, 'react native')) {
            return <<<'CODE'
import React, { useState, useEffect, useCallback } from 'react';
import { StyleSheet, Text, View, FlatList, ActivityIndicator, TouchableOpacity, RefreshControl } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';

interface CourseItem {
    id: number;
    title: string;
    progress: number;
}

export const StudentCoursesScreen: React.FC<{ navigation: any }> = ({ navigation }) => {
    const [courses, setCourses] = useState<CourseItem[]>([]);
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);

    const fetchCourses = useCallback(async () => {
        try {
            const cached = await AsyncStorage.getItem('@cached_courses');
            if (cached) setCourses(JSON.parse(cached));

            const response = await fetch('http://10.0.2.2:8000/api/courses');
            const data = await response.json();
            setCourses(data);
            await AsyncStorage.setItem('@cached_courses', JSON.stringify(data));
        } catch (error) {
            console.warn('[Offline Mode]: Loaded cached courses');
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    }, []);

    useEffect(() => { fetchCourses(); }, [fetchCourses]);

    if (loading) return <View style={styles.center}><ActivityIndicator size="large" color="#2563eb" /></View>;

    return (
        <View style={styles.container}>
            <FlatList
                data={courses}
                keyExtractor={(item) => item.id.toString()}
                refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => { setRefreshing(true); fetchCourses(); }} />}
                renderItem={({ item }) => (
                    <TouchableOpacity style={styles.card} onPress={() => navigation.navigate('LessonPlayer', { courseId: item.id })}>
                        <Text style={styles.cardTitle}>{item.title}</Text>
                        <Text style={styles.cardProgress}>Progress: {item.progress}%</Text>
                    </TouchableOpacity>
                )}
            />
        </View>
    );
};

const styles = StyleSheet.create({
    container: { flex: 1, backgroundColor: '#0f172a', padding: 16 },
    center: { flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: '#0f172a' },
    card: { backgroundColor: '#1e293b', borderRadius: 16, padding: 18, marginBottom: 12, borderWidth: 1, borderColor: '#334155' },
    cardTitle: { fontSize: 16, fontWeight: '700', color: '#f8fafc', marginBottom: 4 },
    cardProgress: { fontSize: 13, color: '#38bdf8', fontWeight: '600' }
});
CODE;
        }

        // 3. UI/UX + CSS + Figma Tokens
        if (str_contains($lowerCat, 'design') || str_contains($lowerTitle, 'ui') || str_contains($lowerTitle, 'ux') || str_contains($lowerTitle, 'figma') || str_contains($lowerTitle, 'css')) {
            return <<<'CODE'
/* 8pt Spatial Design System & CSS Custom Property Tokens */
:root {
  /* Typography Scale */
  --font-family-sans: 'Inter', system-ui, -apple-system, sans-serif;
  --font-size-xs: 0.75rem;    /* 12px */
  --font-size-sm: 0.875rem;   /* 14px */
  --font-size-base: 1.0rem;    /* 16px */
  --font-size-lg: 1.125rem;   /* 18px */
  --font-size-xl: 1.5rem;     /* 24px */

  /* 8pt Spatial Spacing Grid */
  --space-1: 0.25rem;  /* 4px */
  --space-2: 0.5rem;   /* 8px */
  --space-3: 0.75rem;  /* 12px */
  --space-4: 1.0rem;   /* 16px */
  --space-6: 1.5rem;   /* 24px */
  --space-8: 2.0rem;   /* 32px */

  /* Accessible Color Palette (WCAG 2.1 AAA Contrast) */
  --color-surface-bg: #0f172a;
  --color-surface-card: #1e293b;
  --color-border-subtle: #334155;
  --color-text-primary: #f8fafc;
  --color-text-secondary: #94a3b8;
  --color-primary-500: #3b82f6;
  --color-primary-600: #2563eb;
  --radius-xl: 1rem;   /* 16px */
}

/* Responsive Auto-Layout Card Pattern */
.design-system-card {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  padding: var(--space-6);
  background-color: var(--color-surface-card);
  border: 1px solid var(--color-border-subtle);
  border-radius: var(--radius-xl);
  color: var(--color-text-primary);
  transition: transform 200ms ease, box-shadow 200ms ease;
}

.design-system-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.4);
}
CODE;
        }

        // 4. Data Science / Pandas / NumPy
        if (str_contains($lowerCat, 'data science') || str_contains($lowerCat, 'data analyst') || str_contains($lowerTitle, 'pandas') || str_contains($lowerTitle, 'numpy') || str_contains($lowerTitle, 'data')) {
            return <<<'CODE'
import numpy as np
import pandas as pd

# 1. NumPy Vectorized Computing & Broadcasting
matrix_a = np.array([[1.5, 2.8, 3.2], [4.1, 5.0, 6.7]])
weights = np.array([0.2, 0.5, 0.3])

# Vectorized dot product across rows
weighted_scores = np.dot(matrix_a, weights)
print(f"NumPy Weighted Scores: {weighted_scores}")

# 2. Pandas DataFrame Cleaning, Transformation & Grouping
raw_data = {
    'student_id': [101, 102, 103, 104, 105, 106],
    'course_category': ['Cyber Security', 'Full Stack', 'Cyber Security', 'Data Science', 'Full Stack', 'Data Science'],
    'completion_rate': [0.95, 0.45, 1.00, np.nan, 0.80, 0.88],
    'quiz_score': [88, 72, 94, 65, 82, 91]
}

df = pd.DataFrame(raw_data)

# Handle Missing Data with Conditional Median Imputation
df['completion_rate'] = df.groupby('course_category')['completion_rate'].transform(
    lambda x: x.fillna(x.median())
)

# Aggregated Metrics per Category (KPI Pivot Table)
summary_table = df.groupby('course_category').agg(
    total_students=('student_id', 'count'),
    avg_completion=('completion_rate', lambda x: f"{x.mean()*100:.1f}%"),
    avg_quiz_score=('quiz_score', 'mean')
).reset_index()

print("\n--- Course Performance Summary ---")
print(summary_table.to_string(index=False))
CODE;
        }

        // 5. Digital Marketing & Business
        if (str_contains($lowerCat, 'marketing') || str_contains($lowerTitle, 'marketing') || str_contains($lowerTitle, 'seo') || str_contains($lowerTitle, 'analytics')) {
            return <<<'CODE'
-- Marketing Analytics: Multi-Touch Attribution & CAC / LTV Analysis
WITH CampaignPerformance AS (
    SELECT
        utm_campaign,
        utm_source,
        COUNT(DISTINCT user_id) AS total_leads,
        COUNT(DISTINCT CASE WHEN event_name = 'enrollment_completed' THEN user_id END) AS paid_enrollments,
        SUM(ad_spend) AS total_cost,
        SUM(CASE WHEN event_name = 'enrollment_completed' THEN transaction_value ELSE 0 END) AS total_revenue
    FROM marketing_attribution_events
    WHERE event_timestamp >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)
    GROUP BY utm_campaign, utm_source
)
SELECT
    utm_campaign,
    utm_source,
    total_leads,
    paid_enrollments,
    ROUND((paid_enrollments / total_leads) * 100, 2) AS conversion_rate_percent,
    total_cost,
    total_revenue,
    ROUND(total_cost / NULLIF(paid_enrollments, 0), 2) AS customer_acquisition_cost_cac,
    ROUND((total_revenue - total_cost) / NULLIF(total_cost, 0) * 100, 1) AS roas_percentage
FROM CampaignPerformance
ORDER BY paid_enrollments DESC;
CODE;
        }

        // 6. Healthcare / Medical Coding
        if (str_contains($lowerCat, 'healthcare') || str_contains($lowerTitle, 'medical') || str_contains($lowerTitle, 'coding') || str_contains($lowerTitle, 'icd')) {
            return <<<'CODE'
/* Healthcare Data Exchange & ICD-10-CM / CPT Code Validation */
interface MedicalCodingRecord {
    encounterId: string;
    patientId: string;
    principalDiagnosisCode: string; // e.g. "I10" (Essential Primary Hypertension)
    secondaryDiagnosisCodes: string[]; // e.g. ["E11.9" (Type 2 diabetes without complications)]
    procedureCptCodes: string[]; // e.g. ["99214" (Office/Outpatient visit, established patient)]
    billingStatus: 'PENDING_AUDIT' | 'COMPLIANT' | 'REJECTED';
}

function validateCodingCompliance(record: MedicalCodingRecord): { valid: boolean; auditErrors: string[] } {
    const errors: string[] = [];
    const icd10Regex = /^[A-TV-Z][0-9][0-9A-TV-Z](\.[0-9A-TV-Z]{1,4})?$/;
    const cptRegex = /^[0-9]{4}[0-9A-Z]$/;

    if (!icd10Regex.test(record.principalDiagnosisCode)) {
        errors.push(`Invalid Principal ICD-10 Code format: ${record.principalDiagnosisCode}`);
    }

    record.procedureCptCodes.forEach(cpt => {
        if (!cptRegex.test(cpt)) errors.push(`Invalid CPT Code format: ${cpt}`);
    });

    return { valid: errors.length === 0, auditErrors: errors };
}
CODE;
        }

        // Fallback default
        return <<<'CODE'
// Domain Implementation Pattern
export function executeCoreWorkflow(payload: Record<string, unknown>): { status: string; result: unknown } {
    if (!payload || Object.keys(payload).length === 0) {
        throw new Error('Validation failed: payload cannot be empty.');
    }
    return { status: 'SUCCESS', result: { processedAt: new Date().toISOString(), ...payload } };
}
CODE;
    }

    /**
     * Return domain-specific technical deep-dives.
     */
    private function getDomainDeepDive(string $title, string $category, string $level, string $section): string
    {
        $lowerTitle = strtolower($title);
        $lowerCat = strtolower($category);

        if (str_contains($lowerCat, 'testing') || str_contains($lowerTitle, 'playwright')) {
            return <<<TEXT
In modern automated quality engineering, **{$title}** is crucial for ensuring software reliability across browsers and platforms. In Playwright, tests operate inside isolated Browser Contexts—the equivalent of brand-new incognito profiles—eliminating state leakage without the overhead of launching separate browser processes.

#### 1. Auto-Waiting & Resilient Locators
Unlike legacy frameworks that require explicit sleep statements (`sleep(5000)`), Playwright performs automatic actionability checks (visible, stable, enabled, editable) before performing clicks or fills. Using semantic locators such as `page.getByRole()`, `page.getByLabel()`, and `page.getByTestId()` ensures tests remain resilient against frequent CSS class refactoring.

#### 2. Network Mocking & Authentication State
For scalable CI/CD pipelines, tests can persist authenticated browser storage states (`storageState.json`) and intercept external third-party HTTP routes with `page.route()`, reducing test runtimes from minutes to seconds.
TEXT;
        }

        if (str_contains($lowerCat, 'mobile') || str_contains($lowerTitle, 'react native')) {
            return <<<TEXT
In cross-platform mobile engineering, **{$title}** governs how mobile applications interact with native iOS and Android APIs. React Native translates JSX component trees into native UIKit (iOS) and Android Views via the modern Fabric rendering pipeline and JSI (JavaScript Interface), bypassing asynchronous JSON bridge bottlenecks.

#### 1. State Persistence & Offline Synchronization
Mobile applications frequently encounter unstable network connections. Production React Native apps implement optimistic UI updates, local cache layers using `@react-native-async-storage/async-storage` or MMKV, and background retry queues when device connectivity is restored.

#### 2. Platform-Specific Adaptations
Using `Platform.select({ ios: ..., android: ... })` and native permissions handlers allows mobile engineers to handle OS-level differences in safe area insets, keyboard avoidance, and push notification tokens cleanly.
TEXT;
        }

        if (str_contains($lowerCat, 'design') || str_contains($lowerTitle, 'figma') || str_contains($lowerTitle, 'ui')) {
            return <<<TEXT
In modern product design systems, **{$title}** bridges the gap between Figma design tokens and production CSS code. Adhering to an 8pt spatial grid ensures layout rhythm, visual hierarchy, and predictable spacing across mobile and desktop breakpoints.

#### 1. Token Architecture & Component Variants
By defining atomic tokens for color, typography, elevation, and corner radii in CSS Custom Properties, design changes propagate instantly throughout the application. In Figma, Auto Layout frames with horizontal and vertical constraints directly mirror CSS Flexbox and Grid behaviors.

#### 2. Accessibility & Contrast Compliance (WCAG 2.1)
All interface components must meet WCAG 2.1 AA/AAA compliance, requiring a minimum contrast ratio of 4.5:1 for normal text and 3:1 for large headings and interactive UI controls.
TEXT;
        }

        if (str_contains($lowerCat, 'data science') || str_contains($lowerTitle, 'pandas') || str_contains($lowerTitle, 'numpy')) {
            return <<<TEXT
In data science workflows, **{$title}** is fundamental for transforming unstructured raw records into clean, modeled feature sets ready for machine learning pipelines.

#### 1. Vectorized Computing vs. Iterative Loops
NumPy achieves 100x performance speedups over native Python loops by leveraging contiguous C-memory buffers (C-arrays) and SIMD CPU vectorization. When applying mathematical transformations across arrays, broadcasting rules automatically align dimensions without memory duplication.

#### 2. Pandas Data Wrangling & Feature Imputation
Handling missing values (`NaN`), outliers, and categorical encoding with Pandas requires systematic data profiling. Using `groupby()`, `pivot_table()`, and `.transform()` allows analysts to calculate windowed averages, cohort retention, and demographic metrics efficiently.
TEXT;
        }

        return <<<TEXT
In {$category}, **{$title}** represents a fundamental capability. Within {$section}, mastering this topic enables engineers to construct reliable, maintainable systems that scale effectively while maintaining strict data integrity and operational performance.
TEXT;
    }

    /**
     * Return domain pitfalls.
     */
    private function getDomainPitfalls(string $title, string $category): string
    {
        return <<<TEXT
- **Implicit Assumptions & Fragile Selectors**: Avoid brittle absolute XPath or generic tag selectors; rely on semantic roles and test IDs.
- **Unmanaged Asynchronous State**: Never neglect promise rejections, network timeouts, or unhandled exceptions in asynchronous operations.
- **Ignoring Edge-Case Data Types**: Account for null values, empty collections, and extreme boundary values in all data transformations.
- **Hardcoding Secrets & Local Endpoints**: Always extract environment-specific configurations into secure environment variables.
TEXT;
    }

    /**
     * Return domain practice task.
     */
    private function getDomainPracticeTask(string $title, string $category, string $level): string
    {
        return <<<TEXT
1. **Sandbox Setup**: Launch your local development workspace or terminal.
2. **Execute Reference Implementation**: Implement the code sample provided above and adapt it with custom input parameters.
3. **Boundary Testing**: Test your implementation with invalid inputs and verify that defensive fallback handling functions as expected.
4. **Takeaways**: Document your observations, runtime performance metrics, and insights in the *My Notes* tab.
TEXT;
    }

    /**
     * Populate multi-question domain quizzes.
     */
    private function populateDomainQuizQuestions(Quiz $quiz, Lesson $lesson, Section $section, Course $course): void
    {
        $quiz->questions()->delete();
        $title = $lesson->title;
        $category = $course->category;

        // Question 1
        $q1 = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => "In the context of {$category}, what is the primary objective of {$title}?",
            'type' => 'multiple_choice',
            'marks' => 5,
            'sort_order' => 1,
        ]);
        QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Enforcing deterministic execution, modular encapsulation, and defensive error boundaries.', 'is_correct' => true]);
        QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Using hardcoded global sleep delays and bypassing input validation.', 'is_correct' => false]);
        QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Disabling test assertions and ignoring runtime exceptions.', 'is_correct' => false]);
        QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Directly mutating immutable production storage without schema checks.', 'is_correct' => false]);

        // Question 2
        $q2 = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => "Which technique is considered an industry best practice when working with {$title}?",
            'type' => 'multiple_choice',
            'marks' => 5,
            'sort_order' => 2,
        ]);
        QuizOption::create(['question_id' => $q2->id, 'option_text' => 'Applying vectorized transformations, semantic locators, and resilient design tokens.', 'is_correct' => true]);
        QuizOption::create(['question_id' => $q2->id, 'option_text' => 'Relying on brittle absolute coordinate clicks and manual string parsing.', 'is_correct' => false]);
        QuizOption::create(['question_id' => $q2->id, 'option_text' => 'Executing full table scans on every client interaction.', 'is_correct' => false]);
        QuizOption::create(['question_id' => $q2->id, 'option_text' => 'Hardcoding API credentials in client-side bundles.', 'is_correct' => false]);

        // Question 3
        $q3 = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => "How should edge cases and unexpected failure states be managed in {$title}?",
            'type' => 'multiple_choice',
            'marks' => 5,
            'sort_order' => 3,
        ]);
        QuizOption::create(['question_id' => $q3->id, 'option_text' => 'Catch exceptions cleanly, log diagnostic telemetry, and return structured fallback states.', 'is_correct' => true]);
        QuizOption::create(['question_id' => $q3->id, 'option_text' => 'Silently suppress errors and return undefined null pointers.', 'is_correct' => false]);
        QuizOption::create(['question_id' => $q3->id, 'option_text' => 'Crash the active application process immediately without user notification.', 'is_correct' => false]);
        QuizOption::create(['question_id' => $q3->id, 'option_text' => 'Expose raw database credentials in client logs.', 'is_correct' => false]);
    }

    /**
     * Generate domain-specific assignments.
     */
    private function generateDomainAssignment(Lesson $lesson, Section $section, Course $course): string
    {
        $title = $lesson->title;
        $courseTitle = $course->title;
        $category = $course->category;

        return <<<MD
# Practical Project Milestone: {$title}

## 📋 Business Scenario & Technical Goal
As a technical specialist in **{$category}**, your task is to design, implement, and verify a production-ready solution for **{$title}** within the **{$courseTitle}** program.

---

## 🛠️ Required Technical Deliverables
1. **Core Implementation**: Build the functional code, test suites, or design components fulfilling the module specifications.
2. **Defensive Error Handling**: Ensure edge cases, missing data, and unexpected inputs are caught and handled gracefully.
3. **Verification Summary**: Submit test execution results, screenshots, or performance benchmarks demonstrating verified compliance.
4. **Source Archive**: Provide your source code files, configuration scripts, or test suite archive.

---

## 📊 Evaluation Rubric (Total: 100 Marks)
- **Functional Correctness (40 Marks)**: Solution correctly implements all requirements and passes verification assertions.
- **Code / Design Quality (25 Marks)**: Follows clean architecture, naming standards, and modular separation.
- **Resilience & Security (20 Marks)**: Gracefully manages errors, edge cases, and accessibility/security standards.
- **Documentation & Test Proof (15 Marks)**: Clear explanation of implementation and verification logs.
MD;
    }
}
