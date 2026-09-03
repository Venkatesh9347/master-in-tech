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

class RichContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Starting MasterInTech Rich Content Expansion...');

        $lessons = Lesson::with(['course', 'section', 'quiz', 'assignment'])->get();
        $enrichedCount = 0;
        $quizExpandedCount = 0;
        $assignmentExpandedCount = 0;

        foreach ($lessons as $lesson) {
            $course = $lesson->course;
            $section = $lesson->section;

            if (! $course || ! $section) {
                continue;
            }

            // 1. Enrich Lesson Content if empty, short, or placeholder
            $meta = $lesson->metadata;
            if (is_string($meta)) {
                $meta = json_decode($meta, true) ?: [];
            } elseif (! is_array($meta)) {
                $meta = [];
            }

            $currentContent = $meta['content'] ?? null;
            $isPlaceholder = empty($currentContent) || $currentContent === 'NO_CONTENT' || strlen(trim((string) $currentContent)) <= 120;

            if ($isPlaceholder) {
                $richContent = $this->generateRichLessonContent($lesson, $section, $course);
                $meta['content'] = $richContent;
                $lesson->metadata = $meta;
                $lesson->save();
                $enrichedCount++;
            }

            // 2. Expand Quiz Questions & Explanations if quiz exists
            if ($lesson->type === 'quiz' || $lesson->quiz) {
                $quiz = $lesson->quiz ?: Quiz::firstOrCreate(
                    ['lesson_id' => $lesson->id],
                    [
                        'title' => $lesson->title . ' Checkpoint',
                        'description' => "Assess your core technical understanding of {$section->title}.",
                        'passing_score' => 75,
                        'time_limit' => 20,
                        'is_published' => true,
                    ]
                );

                if ($quiz->questions()->count() < 3) {
                    $this->populateQuizQuestions($quiz, $lesson, $section, $course);
                    $quizExpandedCount++;
                }
            }

            // 3. Expand Assignment & Project Deliverables & Rubrics
            if ($lesson->type === 'assignment' || $lesson->type === 'project' || $lesson->assignment) {
                $assignment = $lesson->assignment ?: Assignment::firstOrCreate(
                    ['lesson_id' => $lesson->id],
                    [
                        'course_id' => $course->id,
                        'title' => $lesson->title . ' Practical Milestone',
                        'instructions' => "Practical submission for {$lesson->title}.",
                        'max_marks' => 100,
                        'due_date' => now()->addDays(30),
                        'is_published' => true,
                    ]
                );

                $assignment->instructions = $this->generateAssignmentInstructions($lesson, $section, $course);
                $assignment->save();
                $assignmentExpandedCount++;
            }
        }

        $this->command->info("Rich Content Expansion Complete:");
        $this->command->info("- Lessons Enriched: {$enrichedCount}");
        $this->command->info("- Quizzes Expanded: {$quizExpandedCount}");
        $this->command->info("- Assignments / Capstones Expanded: {$assignmentExpandedCount}");
    }

    /**
     * Generate in-depth educational Markdown content tailored to the lesson.
     */
    private function generateRichLessonContent(Lesson $lesson, Section $section, Course $course): string
    {
        $title = $lesson->title;
        $category = strtoupper($course->category ?? 'TECH');
        $level = $course->difficulty ?? 'Intermediate';
        $sectionTitle = $section->title;
        $courseTitle = $course->title;

        // Custom hand-crafted generators for core topics
        if (str_contains(strtolower($title), 'welcome to the course') || str_contains(strtolower($title), 'getting started')) {
            return <<<MD
# 🎓 Welcome to {$courseTitle} ({$level} Level)

Welcome to **{$courseTitle}** on the MasterInTech Learning Platform! This program is designed to take you through an authentic, job-oriented curriculum covering fundamental concepts, real-world industry patterns, and hands-on implementations.

---

### 🎯 Course Objectives & Learning Outcomes
In this comprehensive course, you will:
- Master core theoretical foundations, architectural standards, and practical implementation patterns.
- Work through hands-on coding exercises, scenario-based checkpoints, and production-style projects.
- Learn industry best practices, common architectural pitfalls, and optimization techniques.
- Build a verifiable portfolio project demonstrating real-world technical competence.

---

### 🛠️ Environment Prerequisites & Setup
Before beginning subsequent modules:
1. Ensure you have modern development tooling installed (IDE such as VS Code, terminal/shell environment, and required runtimes).
2. Configure version control with Git for tracking and submitting practical assignments.
3. Review the curriculum sidebar to understand module milestones, checkpoints, and capstones.

---

### 💡 Tips for Maximizing Learning
- **Active Practice**: Replicate every code example and practical demonstration in your own local environment.
- **Personal Notes**: Utilize the *My Notes* tab on the lower panel to summarize key takeaways.
- **Discussion Forum**: If you encounter edge cases or have architectural questions, post in the *Q&A Forum* tab to interact with instructors and peers.
MD;
        }

        if (str_contains(strtolower($title), 'what you will learn')) {
            return <<<MD
# 🗺️ Curriculum Blueprint & Competency Roadmap

This roadmap outlines the key competencies and milestones you will master throughout **{$courseTitle}**.

---

### 📌 Milestone Progression
1. **Foundations & Core Mechanics**: Establishing clean terminology, architectural mental models, and local lab setup.
2. **Intermediate Practical Integration**: Building functional components, executing queries, handling data streams, and resolving errors.
3. **Advanced Production Architecture**: Security hardening, performance tuning, scaling strategies, and automated CI/CD deployment.
4. **Capstone Evaluation**: Delivering a verified end-to-end project adhering to industry standards.

---

### 🔑 Key Skills Covered in this Track
- Systematic problem solving and defensive programming.
- Production-grade code formatting, testing, and debugging.
- Architectural design patterns tailored for {$category}.
MD;
        }

        // Generate tailored deep technical guides based on category and title keywords
        $codeSnippet = $this->getRelevantCodeSnippet($title, $category, $level);
        $explanation = $this->getDetailedExplanation($title, $category, $level, $sectionTitle);
        $pitfalls = $this->getCommonPitfalls($title, $category);
        $task = $this->getHandsOnTask($title, $category, $level);

        return <<<MD
# {$title}

**Track**: {$category} • **Level**: {$level} • **Module**: {$sectionTitle}

---

### 🎯 Learning Objectives
By completing this lesson, you will be able to:
- Understand the core architectural principles and internal mechanics of **{$title}**.
- Implement practical, production-ready solutions conforming to modern industry standards.
- Diagnose and troubleshoot common configuration errors, security risks, and performance bottlenecks.
- Apply practical best practices in your development workflow and course assignments.

---

### 📚 In-Depth Technical Deep-Dive
{$explanation}

---

### 💻 Practical Implementation & Syntax Guide
The following practical example illustrates the standard production pattern for this concept:

```
{$codeSnippet}
```

#### Step-by-Step Implementation Breakdown:
1. **Initialization & Setup**: Ensure proper configuration, dependency imports, and environment isolation.
2. **Execution & Logic Flow**: Observe how data structures and parameters are validated before execution.
3. **Error Boundaries & Safety**: Defensive error handling ensures unexpected states fail gracefully without crashing upstream services.

---

### ⚠️ Common Pitfalls & Security Best Practices
{$pitfalls}

---

### 🔑 Key Takeaways
- **Precision**: Adhere strictly to verified design patterns rather than ad-hoc workarounds.
- **Scalability**: Design components to operate reliably as workload and data volume scale.
- **Defensive Design**: Validate inputs, sanitize data streams, and verify return values at module boundaries.

---

### 🛠️ Hands-On Practice Task
{$task}
MD;
    }

    /**
     * Return code snippets tailored to the lesson topic and category.
     */
    private function getRelevantCodeSnippet(string $title, string $category, string $level): string
    {
        $lowerTitle = strtolower($title);
        $lowerCat = strtolower($category);

        if (str_contains($lowerTitle, 'cia triad') || str_contains($lowerTitle, 'security fundamentals')) {
            return <<<CODE
// Example: Implementing Integrity & Authentication in Node.js
const crypto = require('crypto');

function verifyPayloadIntegrity(payload, secretKey, receivedHmac) {
    const calculatedHmac = crypto
        .createHmac('sha256', secretKey)
        .update(JSON.stringify(payload))
        .digest('hex');

    // Constant-time comparison to prevent timing attacks
    return crypto.timingSafeEqual(
        Buffer.from(calculatedHmac, 'hex'),
        Buffer.from(receivedHmac, 'hex')
    );
}
CODE;
        }

        if (str_contains($lowerTitle, 'nmap') || str_contains($lowerTitle, 'scanning')) {
            return <<<'CODE'
# 1. TCP SYN Stealth Scan with OS Detection and Version Fingerprinting
nmap -sS -sV -O -p- --min-rate 1000 10.10.10.25 -oN nmap_full_scan.txt

# 2. Focused Vulnerability Script Scanning on Discovered Web Ports
nmap -p 80,443 --script "vuln and safe" 10.10.10.25 -oN nmap_vuln_scripts.txt
CODE;
        }

        if (str_contains($lowerTitle, 'burp') || str_contains($lowerTitle, 'sqli') || str_contains($lowerTitle, 'sql injection')) {
            return <<<'CODE'
-- Vulnerable Dynamic SQL (Anti-Pattern):
-- SELECT * FROM users WHERE username = 'admin' AND password = '' OR '1'='1';

-- Secure Parameterized Query (PDO / Prepared Statement):
$stmt = $pdo->prepare('SELECT id, username, role, password_hash FROM users WHERE username = :username LIMIT 1');
$stmt->execute([':username' => $sanitizedInputUsername]);
$user = $stmt->fetch();
CODE;
        }

        if (str_contains($lowerCat, 'database') || str_contains($lowerTitle, 'sql') || str_contains($lowerTitle, 'query')) {
            return <<<'CODE'
-- Advanced Window Function & CTE Example: Top Performing Records per Category
WITH RankedPerformance AS (
    SELECT
        employee_id,
        department_id,
        sales_revenue,
        ROW_NUMBER() OVER (
            PARTITION BY department_id
            ORDER BY sales_revenue DESC
        ) AS rank_in_dept,
        AVG(sales_revenue) OVER (
            PARTITION BY department_id
        ) AS dept_avg_sales
    FROM sales_records
    WHERE transaction_date >= '2026-01-01'
)
SELECT
    department_id,
    employee_id,
    sales_revenue,
    dept_avg_sales
FROM RankedPerformance
WHERE rank_in_dept <= 3
ORDER BY department_id, rank_in_dept;
CODE;
        }

        if (str_contains($lowerTitle, 'react') || str_contains($lowerTitle, 'hook') || str_contains($lowerTitle, 'state')) {
            return <<<'CODE'
import React, { useReducer, useEffect } from 'react';

interface State {
    data: any[];
    loading: boolean;
    error: string | null;
}

type Action =
    | { type: 'FETCH_START' }
    | { type: 'FETCH_SUCCESS'; payload: any[] }
    | { type: 'FETCH_ERROR'; error: string };

function reducer(state: State, action: Action): State {
    switch (action.type) {
        case 'FETCH_START':
            return { ...state, loading: true, error: null };
        case 'FETCH_SUCCESS':
            return { ...state, loading: false, data: action.payload };
        case 'FETCH_ERROR':
            return { ...state, loading: false, error: action.error };
        default:
            return state;
    }
}
CODE;
        }

        if (str_contains($lowerCat, 'ai') || str_contains($lowerTitle, 'rag') || str_contains($lowerTitle, 'llm') || str_contains($lowerTitle, 'prompt')) {
            return <<<'CODE'
import { OpenAI } from 'openai';

const client = new OpenAI({ apiKey: process.env.OPENAI_API_KEY });

async function queryRAGPipeline(userPrompt: string, contextChunks: string[]) {
    const systemPrompt = `You are an expert technical advisor. Answer using ONLY the provided context chunks.
Context:
${contextChunks.map((c, i) => `[${i+1}] ${c}`).join('\n\n')}`;

    const response = await client.chat.completions.create({
        model: 'gpt-4o-mini',
        messages: [
            { role: 'system', content: systemPrompt },
            { role: 'user', content: userPrompt }
        ],
        temperature: 0.1,
    });

    return response.choices[0].message.content;
}
CODE;
        }

        if (str_contains($lowerCat, 'cloud') || str_contains($lowerTitle, 'vpc') || str_contains($lowerTitle, 'docker') || str_contains($lowerTitle, 'kubernetes')) {
            return <<<'CODE'
# Kubernetes Deployment Spec with Health Probes & Resource Limits
apiVersion: apps/v1
kind: Deployment
metadata:
  name: lms-api-service
  labels:
    app: lms-api
spec:
  replicas: 3
  selector:
    matchLabels:
      app: lms-api
  template:
    metadata:
      labels:
        app: lms-api
    spec:
      containers:
      - name: api
        image: masterintech/api:v2.4
        ports:
        - containerPort: 8000
        resources:
          limits:
            cpu: "500m"
            memory: "512Mi"
          requests:
            cpu: "100m"
            memory: "128Mi"
        livenessProbe:
          httpGet:
            path: /api/health
            port: 8000
          initialDelaySeconds: 15
          periodSeconds: 10
CODE;
        }

        if (str_contains($lowerCat, 'sap') || str_contains($lowerTitle, 'sap')) {
            return <<<'CODE'
* SAP Standard Transaction & Process Mapping:
* 1. Master Data Creation: MM01 (Material Master), BP (Business Partner)
* 2. Purchase Requisition: ME51N -> Purchase Order: ME21N
* 3. Goods Receipt: MIGO (Movement Type 101 - Inbound Delivery)
* 4. Invoice Verification: MIRO (Logistics Invoice Verification)
* 5. General Ledger Posting: FB50 / Financial Statement: F.01
CODE;
        }

        // Generic modern programming snippet
        return <<<CODE
// Production Implementation Blueprint for: {$title}
export class OperationalService {
    private readonly configuration: Record<string, unknown>;

    constructor(config: Record<string, unknown>) {
        this.configuration = Object.freeze({ ...config });
    }

    public async executeWorkflow(inputPayload: unknown): Promise<{ success: boolean; data: unknown }> {
        try {
            this.validateInput(inputPayload);
            const processedResult = await this.processPipeline(inputPayload);
            return { success: true, data: processedResult };
        } catch (error) {
            console.error('[Workflow Error]:', error instanceof Error ? error.message : error);
            throw error;
        }
    }

    private validateInput(payload: unknown): void {
        if (!payload || typeof payload !== 'object') {
            throw new Error('Invalid payload: Object required.');
        }
    }

    private async processPipeline(payload: unknown): Promise<unknown> {
        return { timestamp: new Date().toISOString(), processed: true, payload };
    }
}
CODE;
    }

    /**
     * Provide detailed multi-paragraph technical explanations.
     */
    private function getDetailedExplanation(string $title, string $category, string $level, string $section): string
    {
        return <<<TEXT
In modern enterprise engineering, **{$title}** represents an essential architectural foundation. Within {$section}, understanding how this layer interacts with upstream client interfaces and downstream persistence engines is vital for ensuring high availability, data integrity, and low-latency throughput.

#### 1. Core Mechanics & Execution Lifecycle
When working with {$title}, the execution flow begins by validating contracts at the service boundary. By isolating responsibility into modular units, applications avoid tight coupling, enabling seamless refactoring, automated testing, and horizontal scaling.

#### 2. Architectural Design Patterns
Depending on the deployment topology, industry best practices dictate adhering to strict separation of concerns. In {$level}-level systems, this translates to utilizing structured abstractions, dependency injection, and centralized configuration management rather than scattered global state.

#### 3. Real-World Industry Application
In production environments, systems utilizing this pattern achieve predictable latency and maintainable codebases. When combined with comprehensive logging and observability, engineering teams can quickly detect regressions and optimize throughput.
TEXT;
    }

    /**
     * Provide common pitfalls.
     */
    private function getCommonPitfalls(string $title, string $category): string
    {
        return <<<TEXT
- **Unvalidated Input & Implicit Trust**: Never assume upstream data conforms to schema contracts without defensive validation.
- **Ignoring Concurrency & Race Conditions**: In multi-user distributed environments, always account for simultaneous read/write collisions using transactions or atomic locks.
- **Over-Complicated Abstractions**: Avoid introducing unnecessary layers of indirection before measuring actual operational bottlenecks.
- **Missing Telemetry**: Ensure that errors, latency spikes, and exceptional states are logged with contextual metadata for efficient root-cause analysis.
TEXT;
    }

    /**
     * Provide hands-on practice tasks.
     */
    private function getHandsOnTask(string $title, string $category, string $level): string
    {
        return <<<TEXT
1. **Local Setup**: Open your development environment or terminal and initialize a sandbox project for this module.
2. **Implement the Reference Pattern**: Write and execute the example code provided above, replacing the mock inputs with your own realistic test dataset.
3. **Trigger & Handle Error Cases**: Intentionally pass malformed inputs or simulate network failures to verify that your defensive error handlers catch and log the issue correctly.
4. **Self-Check**: Verify your output against the module learning objectives and record your personal takeaways in the *My Notes* tab.
TEXT;
    }

    /**
     * Populate multi-question quiz banks with scenarios and explanations.
     */
    private function populateQuizQuestions(Quiz $quiz, Lesson $lesson, Section $section, Course $course): void
    {
        $quiz->questions()->delete();

        $title = $lesson->title;
        $courseTitle = $course->title;

        // Question 1: Conceptual Architecture
        $q1 = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => "What is the primary architectural objective when implementing {$title}?",
            'type' => 'multiple_choice',
            'marks' => 5,
            'sort_order' => 1,
        ]);
        QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Enforcing separation of concerns, defensive validation, and modular reusability.', 'is_correct' => true]);
        QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Hardcoding monolithic logic into a single global execution loop.', 'is_correct' => false]);
        QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Disabling authentication and telemetry to minimize execution overhead.', 'is_correct' => false]);
        QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Bypassing schema constraints and allowing unvalidated data storage.', 'is_correct' => false]);

        // Question 2: Error Handling & Troubleshooting
        $q2 = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => "In production environments, how should unexpected failure states in {$title} be handled?",
            'type' => 'multiple_choice',
            'marks' => 5,
            'sort_order' => 2,
        ]);
        QuizOption::create(['question_id' => $q2->id, 'option_text' => 'Catch exceptions at boundaries, log contextual diagnostics, and return structured fallback errors.', 'is_correct' => true]);
        QuizOption::create(['question_id' => $q2->id, 'option_text' => 'Silently swallow errors and return empty null objects to avoid alerts.', 'is_correct' => false]);
        QuizOption::create(['question_id' => $q2->id, 'option_text' => 'Terminate the entire server daemon immediately on the first exception.', 'is_correct' => false]);
        QuizOption::create(['question_id' => $q2->id, 'option_text' => 'Expose raw database connection stack traces directly to unauthenticated clients.', 'is_correct' => false]);

        // Question 3: Best Practice & Optimization
        $q3 = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => "Which practice is critical for maintaining high performance and security in {$title}?",
            'type' => 'multiple_choice',
            'marks' => 5,
            'sort_order' => 3,
        ]);
        QuizOption::create(['question_id' => $q3->id, 'option_text' => 'Applying input validation, index optimization, and constant-time/parameterized queries.', 'is_correct' => true]);
        QuizOption::create(['question_id' => $q3->id, 'option_text' => 'Concatenating user inputs directly into execution statements without escaping.', 'is_correct' => false]);
        QuizOption::create(['question_id' => $q3->id, 'option_text' => 'Executing unbounded full-table scans on every client request.', 'is_correct' => false]);
        QuizOption::create(['question_id' => $q3->id, 'option_text' => 'Storing sensitive credentials in unencrypted plain text source repositories.', 'is_correct' => false]);
    }

    /**
     * Generate structured assignment instructions, deliverables, and rubrics.
     */
    private function generateAssignmentInstructions(Lesson $lesson, Section $section, Course $course): string
    {
        $title = $lesson->title;
        $courseTitle = $course->title;
        $level = $course->difficulty ?? 'Intermediate';

        return <<<MD
# Practical Assignment Milestone: {$title}

## 📋 Project Scenario & Business Requirements
As an engineer working on **{$courseTitle}**, your objective is to implement a robust, production-grade technical solution for **{$title}**. Your implementation must demonstrate clean architecture, defensive data validation, error handling, and comprehensive verification.

---

## 🛠️ Required Technical Deliverables
1. **Core Implementation**: Build the required modules or scripts fulfilling the functional criteria for this module.
2. **Defensive Error Handling**: Ensure invalid inputs, boundary conditions, and unexpected network/database exceptions fail gracefully.
3. **Verification Documentation**: Provide a walkthrough summary including execution logs, terminal output screenshots, or test results demonstrating passing criteria.
4. **Code / Solution Archive**: Submit your clean source code repository link or zipped archive containing your project files.

---

## 📊 Evaluation Rubric (Total: 100 Marks)
- **Functional Correctness (40 Marks)**: Solution correctly implements all stated requirements without logic flaws.
- **Code Quality & Architecture (25 Marks)**: Code follows clean formatting, naming conventions, and modular separation.
- **Error Handling & Security (20 Marks)**: Edge cases and security best practices (input sanitation, authentication) are properly addressed.
- **Documentation & Verification (15 Marks)**: Clear explanation of solution design and test proof.
MD;
    }
}
