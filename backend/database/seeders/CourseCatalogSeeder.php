<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CourseCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Get or create tutor user for instructor assignments
        $tutor = User::where('role', 'tutor')->first();
        if (! $tutor) {
            $tutor = User::create([
                'name' => 'Sarah Johnson (Tutor)',
                'email' => 'tutor@example.com',
                'password' => 'password',
                'role' => 'tutor',
            ]);
        }

        $allCourses = array_merge(
            $this->getBasicCourses(),
            $this->getIntermediateCourses(),
            $this->getAdvancedCourses()
        );

        foreach ($allCourses as $cData) {
            $modules = $cData['modules'] ?? [];
            unset($cData['modules']);

            $cData['instructor_id'] = $tutor->id;
            $cData['difficulty'] = $cData['difficulty'] ?? 'Intermediate';
            $cData['is_published'] = true;
            $cData['status'] = 'published';

            // Safe updateOrCreate on slug
            $course = Course::updateOrCreate(
                ['slug' => $cData['slug']],
                $cData
            );

            // Populate / enrich curriculum
            foreach ($modules as $mIndex => $m) {
                $lessons = $m['lessons'] ?? [];
                $moduleSlug = Str::slug($m['title']) . '-' . $course->id;

                $section = Section::updateOrCreate(
                    [
                        'course_id' => $course->id,
                        'slug' => $moduleSlug,
                    ],
                    [
                        'title' => $m['title'],
                        'description' => $m['description'] ?? "Module {$mIndex}: {$m['title']}",
                        'sort_order' => $mIndex + 1,
                        'is_published' => true,
                    ]
                );

                foreach ($lessons as $lIndex => $l) {
                    $lessonSlug = Str::slug($l['title']) . '-' . $section->id;
                    $lessonType = $l['type'] ?? 'text';

                    $lesson = Lesson::updateOrCreate(
                        [
                            'course_id' => $course->id,
                            'section_id' => $section->id,
                            'slug' => $lessonSlug,
                        ],
                        [
                            'title' => $l['title'],
                            'description' => $l['description'] ?? $l['title'],
                            'type' => $lessonType,
                            'duration' => $l['duration'] ?? '25 min',
                            'sort_order' => $lIndex + 1,
                            'is_published' => true,
                            'metadata' => [
                                'content' => $l['content'] ?? ($l['description'] ?? $l['title']),
                                'video_url' => $l['video_url'] ?? null,
                                'document_url' => $l['document_url'] ?? null,
                                'document_title' => $l['document_title'] ?? null,
                            ],
                        ]
                    );

                    // If quiz lesson
                    if ($lessonType === 'quiz') {
                        $quiz = Quiz::updateOrCreate(
                            ['lesson_id' => $lesson->id],
                            [
                                'title' => $l['title'] . ' Checkpoint',
                                'description' => 'Test your comprehension of the concepts covered in this module.',
                                'passing_score' => 75,
                                'time_limit' => 20,
                                'is_published' => true,
                            ]
                        );

                        if ($quiz->questions()->count() === 0) {
                            $q1 = QuizQuestion::create([
                                'quiz_id' => $quiz->id,
                                'question' => "Which of the following is a primary architectural principle taught in {$m['title']}?",
                                'type' => 'multiple_choice',
                                'marks' => 5,
                                'sort_order' => 1,
                            ]);

                            QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Modular separation of concerns & verified implementation', 'is_correct' => true]);
                            QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Hardcoding monolithic configuration strings', 'is_correct' => false]);
                            QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Skipping authentication & unit test verification', 'is_correct' => false]);
                            QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Direct manual schema mutations without versioning', 'is_correct' => false]);
                        }
                    }

                    // If assignment lesson
                    if ($lessonType === 'assignment') {
                        Assignment::updateOrCreate(
                            ['lesson_id' => $lesson->id],
                            [
                                'course_id' => $course->id,
                                'title' => $l['title'] . ' Capstone Assignment',
                                'instructions' => "Complete the practical milestone for {$course->title}. Implement the requirements in your development/lab environment and submit the repository link and verification documentation.",
                                'max_marks' => 100,
                                'due_date' => now()->addDays(30),
                                'is_published' => true,
                            ]
                        );
                    }
                }
            }
        }
    }

    private function getBasicCourses(): array
    {
        return [
            // Cyber Security Basic
            [
                'title' => 'Cyber Security Fundamentals',
                'slug' => 'cyber-security-fundamentals',
                'category' => 'Cyber Security',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 14999,
                'description' => 'Build essential foundational cybersecurity knowledge covering the CIA triad, network protocols, threat landscapes, and defense principles.',
                'full_description' => "Cyber Security Fundamentals provides the core building blocks required for modern information security careers.\n\nStudents learn network topologies, encryption principles, authentication mechanisms, common vulnerability categories, and security hygiene within safe, authorized environments.",
                'prerequisites' => ['Basic computer literacy', 'Familiarity with web browsers and operating systems'],
                'learning_objectives' => [
                    'Understand the CIA Triad (Confidentiality, Integrity, Availability)',
                    'Identify common attack vectors (Phishing, Malware, Man-in-the-Middle)',
                    'Understand symmetric vs asymmetric cryptography and SSL/TLS',
                    'Configure basic host firewalls and secure browser settings',
                    'Conduct foundational threat modeling for consumer and enterprise apps',
                ],
                'skills_gained' => ['Security Fundamentals', 'Threat Modeling', 'Basic Cryptography', 'Network Defense Basics', 'Security Hygiene'],
                'thumbnail' => 'https://images.unsplash.com/photo-1550751827-4bd374c3f58b?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Foundations of Cybersecurity & CIA Triad',
                        'lessons' => [
                            ['title' => 'Introduction to Information Security & Cyber Threats', 'type' => 'video', 'duration' => '25 min', 'video_url' => 'https://www.youtube.com/watch?v=inWWhr5tnEA'],
                            ['title' => 'The CIA Triad: Confidentiality, Integrity & Availability', 'type' => 'text', 'duration' => '20 min'],
                            ['title' => 'Authentication, Authorization & Accounting (AAA)', 'type' => 'text', 'duration' => '20 min'],
                        ],
                    ],
                    [
                        'title' => 'Module 2: Network & Operating System Basics for Security',
                        'lessons' => [
                            ['title' => 'IP Addresses, Ports, DNS & Routing Fundamentals', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=IPvYjXCsTg8'],
                            ['title' => 'Windows & Linux Security Configurations', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Foundations Security Assessment Checkpoint', 'type' => 'quiz', 'duration' => '20 min'],
                        ],
                    ],
                    [
                        'title' => 'Module 3: Hands-On Security Audit Mini-Project',
                        'lessons' => [
                            ['title' => 'Practical Host Hardening Walkthrough', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'Capstone: Baseline Threat & Vulnerability Audit', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],

            // Database Basic
            [
                'title' => 'Database Fundamentals',
                'slug' => 'database-fundamentals',
                'category' => 'DATABASE',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Aman Verma',
                'price' => 12999,
                'description' => 'Understand relational database concepts, entity-relationship modeling, schema architecture, and data integrity constraints.',
                'full_description' => "Database Fundamentals introduces core data storage concepts, tables, primary/foreign keys, normalization rules (1NF, 2NF, 3NF), and database management systems.",
                'prerequisites' => ['Basic computer operations', 'Familiarity with spreadsheets or structured tables'],
                'learning_objectives' => [
                    'Understand relational database architecture and DBMS vs RDBMS',
                    'Design Entity-Relationship Diagrams (ERDs)',
                    'Implement Primary Keys, Foreign Keys, and Unique Constraints',
                    'Normalize database schemas to Third Normal Form (3NF)',
                    'Understand ACID properties for reliable data storage',
                ],
                'skills_gained' => ['Relational Data Modeling', 'ER Diagrams', 'Database Normalization', 'Schema Design', 'Data Integrity'],
                'thumbnail' => 'https://images.unsplash.com/photo-1544383835-bda2bc66a55d?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Relational Data Models & Architecture',
                        'lessons' => [
                            ['title' => 'Introduction to Data Storage & Relational Models', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'Entities, Attributes, Tables & Rows', 'type' => 'text', 'duration' => '20 min'],
                            ['title' => 'Primary Keys, Foreign Keys & Relationships', 'type' => 'text', 'duration' => '20 min'],
                        ],
                    ],
                    [
                        'title' => 'Module 2: Normalization & Schema Design',
                        'lessons' => [
                            ['title' => 'Database Normalization: 1NF, 2NF, 3NF', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'ACID Transactions & Data Integrity', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Database Modeling Quiz Checkpoint', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Design an E-Commerce Relational Schema', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'SQL Fundamentals',
                'slug' => 'sql-fundamentals',
                'category' => 'DATABASE',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Aman Verma',
                'price' => 12999,
                'description' => 'Master SQL syntax: querying data with SELECT, filtering with WHERE, aggregating with GROUP BY, and joining multiple tables.',
                'full_description' => "SQL Fundamentals provides a hands-on introduction to querying relational databases using standard ANSI SQL. Learn to filter, sort, group, and combine datasets with confidence.",
                'prerequisites' => ['Basic computer literacy'],
                'learning_objectives' => [
                    'Write robust SELECT queries with WHERE filters and ORDER BY clauses',
                    'Perform aggregations using COUNT, SUM, AVG, MIN, MAX with GROUP BY and HAVING',
                    'Combine data across tables using INNER JOIN and LEFT JOIN',
                    'Perform INSERT, UPDATE, and DELETE operations safely',
                ],
                'skills_gained' => ['SQL Querying', 'Data Filtering', 'Aggregate Functions', 'Table Joins', 'DML Statements'],
                'thumbnail' => 'https://images.unsplash.com/photo-1544383835-bda2bc66a55d?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Querying Data with SQL',
                        'lessons' => [
                            ['title' => 'SELECT, FROM, and Column Aliasing', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'Filtering Rows with WHERE, AND, OR, IN, and LIKE', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Sorting and Limiting Results (ORDER BY, LIMIT)', 'type' => 'text', 'duration' => '20 min'],
                        ],
                    ],
                    [
                        'title' => 'Module 2: Aggregations, Joins & Mini-Project',
                        'lessons' => [
                            ['title' => 'GROUP BY, HAVING, and Aggregate Metrics', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Inner Joins vs Left Outer Joins Explained', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'SQL Fundamentals Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Customer Analytics SQL Report', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],

            // Cloud Basic
            [
                'title' => 'Cloud Computing Fundamentals',
                'slug' => 'cloud-computing-fundamentals',
                'category' => 'CLOUD COMPUTING',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 14999,
                'description' => 'Discover the core models of modern cloud computing: IaaS, PaaS, SaaS, public/private clouds, regions, and cost management.',
                'full_description' => "Cloud Computing Fundamentals introduces the shift from traditional on-premise hardware to scalable, on-demand cloud infrastructure across AWS, Azure, and Google Cloud.",
                'prerequisites' => ['Basic computer & internet knowledge'],
                'learning_objectives' => [
                    'Understand IaaS, PaaS, and SaaS service models',
                    'Differentiate Public, Private, and Hybrid cloud deployments',
                    'Understand Regions, Availability Zones, and Global Infrastructure',
                    'Explain virtualization, containers, and serverless computing concepts',
                ],
                'skills_gained' => ['Cloud Models (IaaS/PaaS/SaaS)', 'Cloud Storage', 'Virtualization Basics', 'Cost Governance', 'Cloud Security Basics'],
                'thumbnail' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Cloud Concepts & Service Models',
                        'lessons' => [
                            ['title' => 'What is Cloud Computing? History & Evolution', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'IaaS vs PaaS vs SaaS: Comparison & Use Cases', 'type' => 'text', 'duration' => '20 min'],
                            ['title' => 'Regions, Availability Zones & High Availability', 'type' => 'text', 'duration' => '20 min'],
                        ],
                    ],
                    [
                        'title' => 'Module 2: Cloud Core Services & Mini-Project',
                        'lessons' => [
                            ['title' => 'Compute, Storage, Database & Networking Overview', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Cloud Fundamentals Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Cloud Architecture Selection Report', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],

            // Data Basic
            [
                'title' => 'Data Analysis Fundamentals',
                'slug' => 'data-analysis-fundamentals',
                'category' => 'DATA ANALYST',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 12999,
                'description' => 'Learn essential data analytics concepts, exploratory data inspection, spreadsheet modeling, and business metric reporting.',
                'full_description' => "Data Analysis Fundamentals teaches students how to formulate business questions, clean raw datasets, perform descriptive statistics, and communicate findings visually.",
                'prerequisites' => ['Basic computer operations'],
                'learning_objectives' => [
                    'Understand the data analysis workflow (Ask, Prepare, Process, Analyze, Share)',
                    'Clean dirty datasets and handle missing values',
                    'Calculate descriptive statistics (Mean, Median, Standard Deviation)',
                    'Build clear, effective charts and visualizations for stakeholders',
                ],
                'skills_gained' => ['Data Cleaning', 'Descriptive Statistics', 'Spreadsheet Formulas', 'Data Visualization', 'Business Storytelling'],
                'thumbnail' => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Introduction to Data Analysis',
                        'lessons' => [
                            ['title' => 'The Data Analytics Lifecycle', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'Data Types, Formats, and Measurement Scales', 'type' => 'text', 'duration' => '20 min'],
                            ['title' => 'Data Cleaning & Transformation Techniques', 'type' => 'text', 'duration' => '25 min'],
                        ],
                    ],
                    [
                        'title' => 'Module 2: Statistical Summaries & Visualization',
                        'lessons' => [
                            ['title' => 'Descriptive Statistics & Summary Metrics', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Data Analysis Fundamentals Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Retail Sales Data Analysis Report', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Data Science Fundamentals',
                'slug' => 'data-science-fundamentals',
                'category' => 'DATA SCIENCE',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Dr. Aris Thorne',
                'price' => 14999,
                'description' => 'Foundational introduction to data science, the scientific method in computing, hypothesis testing, and Python for data exploration.',
                'full_description' => "Data Science Fundamentals bridges mathematics, computing, and domain knowledge to turn raw data into predictive and prescriptive business value.",
                'prerequisites' => ['Basic computer literacy', 'High-school level algebra'],
                'learning_objectives' => [
                    'Understand the Data Science lifecycle and machine learning framing',
                    'Perform exploratory data analysis using Python libraries (NumPy, Pandas)',
                    'Formulate hypotheses and interpret p-values and confidence intervals',
                    'Visualize distributions and relationships with Matplotlib and Seaborn',
                ],
                'skills_gained' => ['Data Science Foundations', 'Exploratory Data Analysis', 'Python for Data', 'Hypothesis Formulation', 'Data Visualization'],
                'thumbnail' => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: The Data Science Paradigm',
                        'lessons' => [
                            ['title' => 'What is Data Science? Algorithms & Applications', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'NumPy & Pandas for Tabular Data Manipulation', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'Data Science Foundations Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Exploratory Analysis of Housing Dataset', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Data Engineering Fundamentals',
                'slug' => 'data-engineering-fundamentals',
                'category' => 'DATA ENGINEERING',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 14999,
                'description' => 'Understand data pipelines, ETL vs ELT architectures, batch and streaming paradigms, and data storage formats.',
                'full_description' => "Data Engineering Fundamentals teaches the core engineering foundations needed to collect, store, transform, and deliver clean data to analysts and data scientists.",
                'prerequisites' => ['Basic programming and database awareness'],
                'learning_objectives' => [
                    'Understand the role of Data Engineering in modern tech organizations',
                    'Compare Batch Processing vs Real-Time Streaming architectures',
                    'Differentiate ETL (Extract-Transform-Load) and ELT paradigms',
                    'Work with data storage formats: CSV, JSON, Parquet, and Avro',
                ],
                'skills_gained' => ['ETL/ELT Concepts', 'Data Pipelines', 'Data Formats', 'Batch vs Streaming', 'Data Lake Basics'],
                'thumbnail' => 'https://images.unsplash.com/photo-1558494949-ef010cbdcc31?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Data Pipeline Architectures',
                        'lessons' => [
                            ['title' => 'Core Data Engineering Concepts & Pipeline Stages', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'Data Formats Comparison: Row vs Columnar Storage', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Data Engineering Fundamentals Checkpoint', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Design an End-to-End Data Pipeline Blueprint', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],

            // AI Basic
            [
                'title' => 'Artificial Intelligence Fundamentals',
                'slug' => 'artificial-intelligence-fundamentals',
                'category' => 'ARTIFICIAL INTELLIGENCE',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Dr. Aris Thorne',
                'price' => 14999,
                'description' => 'Understand the history, fundamental concepts, ethical considerations, and core algorithms powering modern AI systems.',
                'full_description' => "Artificial Intelligence Fundamentals provides an accessible, rigorous grounding in search algorithms, heuristic methods, machine learning paradigms, neural networks, and AI ethics.",
                'prerequisites' => ['Basic computer literacy'],
                'learning_objectives' => [
                    'Understand Narrow AI vs General AI and historical milestones',
                    'Explain Supervised, Unsupervised, and Reinforcement Learning',
                    'Understand how neural networks learn via weights and activation functions',
                    'Evaluate AI ethics, bias, transparency, and safety considerations',
                ],
                'skills_gained' => ['AI Concepts', 'ML Paradigms', 'Neural Network Basics', 'AI Ethics', 'Generative AI Basics'],
                'thumbnail' => 'https://images.unsplash.com/photo-1677442136019-21780efad99a?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Core AI Concepts & Learning Models',
                        'lessons' => [
                            ['title' => 'Introduction to Artificial Intelligence', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'Supervised vs Unsupervised vs Reinforcement Learning', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'AI Fundamentals Quiz Checkpoint', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: AI Ethics & System Feasibility Study', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Python Fundamentals',
                'slug' => 'python-fundamentals',
                'category' => 'ARTIFICIAL INTELLIGENCE',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 12999,
                'description' => 'Master foundational Python programming syntax, variables, data structures, control flow, functions, and file I/O.',
                'full_description' => "Python Fundamentals takes you from zero programming experience to confident Python developer, ready for web development, data science, or automation.",
                'prerequisites' => ['Basic computer literacy'],
                'learning_objectives' => [
                    'Write clean Python code using PEP 8 conventions',
                    'Work with data structures: lists, tuples, dictionaries, and sets',
                    'Implement control flow (if/elif/else, for loops, while loops)',
                    'Define functions with arguments, return values, and docstrings',
                    'Handle exceptions gracefully and read/write files',
                ],
                'skills_gained' => ['Python 3', 'Data Structures', 'Functions', 'OOP Basics', 'Error Handling', 'File I/O'],
                'thumbnail' => 'https://images.unsplash.com/photo-1526379095098-d400fd0bf935?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Syntax, Variables & Control Flow',
                        'lessons' => [
                            ['title' => 'Python Installation, Variables & Data Types', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'Conditionals & Loops in Python', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Lists, Dictionaries, Tuples & Sets', 'type' => 'text', 'duration' => '30 min'],
                        ],
                    ],
                    [
                        'title' => 'Module 2: Functions, Modules & Mini-Project',
                        'lessons' => [
                            ['title' => 'Writing Modular Reusable Functions', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'Python Fundamentals Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Python CLI Task & Budget Tracker', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],

            // Full Stack / Programming Basic
            [
                'title' => 'Programming Fundamentals',
                'slug' => 'programming-fundamentals',
                'category' => 'FULL STACK',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 12999,
                'description' => 'Develop computational thinking, algorithmic problem-solving skills, control structures, and code debugging techniques.',
                'full_description' => "Programming Fundamentals establishes core problem-solving habits that apply across any language: variables, logic gates, conditional branching, loops, memory concepts, and functions.",
                'prerequisites' => ['Basic computer literacy'],
                'learning_objectives' => [
                    'Translate human problems into structured algorithmic steps',
                    'Master boolean logic, conditional branching, and loop iterations',
                    'Understand variables, memory scope, and data types',
                    'Debug syntax and runtime errors methodically',
                ],
                'skills_gained' => ['Algorithmic Thinking', 'Logic Flowcharts', 'Debugging', 'Control Flow', 'Problem Solving'],
                'thumbnail' => 'https://images.unsplash.com/photo-1517694712202-14dd9538aa97?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Logic, Algorithms & Data',
                        'lessons' => [
                            ['title' => 'Computational Thinking & Problem Decomposition', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'Variables, Data Types, and Operators', 'type' => 'text', 'duration' => '20 min'],
                            ['title' => 'Conditional Logic and Looping Constructs', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Programming Fundamentals Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Build an Interactive Text Simulation Game', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Computer Fundamentals & IT Essentials',
                'slug' => 'computer-fundamentals-it-essentials',
                'category' => 'FULL STACK',
                'difficulty' => 'Basic',
                'duration' => '4 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 9999,
                'description' => 'Comprehensive foundation in hardware architectures, operating system operations, file systems, and IT troubleshooting.',
                'full_description' => "Computer Fundamentals & IT Essentials covers CPU/RAM architectures, motherboard components, storage technologies, operating system internals, and IT support practices.",
                'prerequisites' => ['None'],
                'learning_objectives' => [
                    'Understand computer hardware components and data buses',
                    'Navigate Windows and Linux operating systems with confidence',
                    'Understand file systems (NTFS, ext4), paths, and file permissions',
                    'Troubleshoot common software and connectivity issues',
                ],
                'skills_gained' => ['Hardware Architecture', 'OS Navigation', 'File Systems', 'IT Troubleshooting', 'Software Management'],
                'thumbnail' => 'https://images.unsplash.com/photo-1518770660439-4636190af475?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Computer Hardware & OS Essentials',
                        'lessons' => [
                            ['title' => 'Hardware Components: CPU, Memory, Storage & I/O', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'Operating Systems & File Systems Overview', 'type' => 'text', 'duration' => '20 min'],
                            ['title' => 'IT Essentials Quiz Checkpoint', 'type' => 'quiz', 'duration' => '15 min'],
                            ['title' => 'Capstone: System Specification & Diagnostics Plan', 'type' => 'assignment', 'duration' => '45 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Git & GitHub Fundamentals',
                'slug' => 'git-github-fundamentals',
                'category' => 'FULL STACK',
                'difficulty' => 'Basic',
                'duration' => '4 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 9999,
                'description' => 'Master Git version control from initialization, branching, committing, merging, to collaborating on GitHub repositories.',
                'full_description' => "Git & GitHub Fundamentals teaches professional version control workflows: tracking history, creating isolated feature branches, opening pull requests, and resolving merge conflicts.",
                'prerequisites' => ['Basic command line familiarity'],
                'learning_objectives' => [
                    'Initialize repositories and manage staging with git add and git commit',
                    'Create, switch, and merge feature branches',
                    'Resolve merge conflicts cleanly using modern diff tools',
                    'Collaborate on GitHub with remote repositories and Pull Requests',
                ],
                'skills_gained' => ['Git Version Control', 'GitHub Collaboration', 'Branching Strategies', 'Merge Conflict Resolution', 'Pull Requests'],
                'thumbnail' => 'https://images.unsplash.com/photo-1618401471353-b98afee0b2eb?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Version Control & GitHub Workflows',
                        'lessons' => [
                            ['title' => 'Git Architecture: Working Directory, Staging, Repository', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'Branching, Merging & Conflict Resolution', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Remote Repositories, Forks & Pull Requests', 'type' => 'text', 'duration' => '20 min'],
                            ['title' => 'Git Fundamentals Quiz', 'type' => 'quiz', 'duration' => '15 min'],
                            ['title' => 'Capstone: Multi-Branch Collaborative GitHub Project', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Linux Fundamentals',
                'slug' => 'linux-fundamentals',
                'category' => 'FULL STACK',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Aman Verma',
                'price' => 12999,
                'description' => 'Navigate the Linux shell, manage file permissions, configure system services, and automate tasks with Bash scripting.',
                'full_description' => "Linux Fundamentals equips students with essential command-line fluency for development, DevOps, and cloud engineering across Ubuntu and RedHat distributions.",
                'prerequisites' => ['Basic computer literacy'],
                'learning_objectives' => [
                    'Navigate the Linux Filesystem Hierarchy (FHS) using terminal commands',
                    'Manage users, groups, and POSIX file permissions (chmod, chown)',
                    'Monitor system processes and manage background services with systemd',
                    'Write basic Bash scripts for automation and text parsing (grep, sed, awk)',
                ],
                'skills_gained' => ['Linux CLI', 'Bash Scripting', 'POSIX Permissions', 'Systemd Services', 'Process Management'],
                'thumbnail' => 'https://images.unsplash.com/photo-1629654297299-c8506221ca97?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Linux CLI & Administration',
                        'lessons' => [
                            ['title' => 'Terminal Navigation & Core Linux Commands', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'Permissions, Ownership & Security (chmod, chown, sudo)', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Processes, Packages & Service Management (systemctl)', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Linux Fundamentals Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Automated Linux Server Provisioning Script', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Networking Fundamentals',
                'slug' => 'networking-fundamentals',
                'category' => 'FULL STACK',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 12999,
                'description' => 'Master the OSI model, TCP/IP protocols, IPv4/IPv6 subnetting, DNS, DHCP, and HTTP/HTTPS client-server communication.',
                'full_description' => "Networking Fundamentals builds essential networking literacy for web engineers, cloud architects, and security analysts.",
                'prerequisites' => ['Basic computer operations'],
                'learning_objectives' => [
                    'Understand the 7 layers of the OSI model and the TCP/IP stack',
                    'Perform IPv4 addressing and CIDR subnetting calculations',
                    'Understand DNS resolution, DHCP assignment, and NAT translation',
                    'Inspect HTTP/HTTPS request-response lifecycles and TLS handshakes',
                ],
                'skills_gained' => ['OSI Model', 'TCP/IP', 'Subnetting (CIDR)', 'DNS/DHCP', 'HTTP/HTTPS Protocol', 'Wireshark Basics'],
                'thumbnail' => 'https://images.unsplash.com/photo-1544197150-b99a580bb7a8?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Protocols & Architecture',
                        'lessons' => [
                            ['title' => 'OSI Model vs TCP/IP Protocol Suite', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'IP Addressing, Subnet Masks & Routing Basics', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'DNS, DHCP, HTTP/HTTPS and TLS Handshakes', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Networking Fundamentals Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Network Topology & Packet Flow Analysis', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Web Development Fundamentals',
                'slug' => 'web-development-fundamentals',
                'category' => 'FULL STACK',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 12999,
                'description' => 'Learn semantic HTML5, modern responsive CSS3 layouts with Flexbox and Grid, and interactive JavaScript DOM manipulation.',
                'full_description' => "Web Development Fundamentals introduces the core triple of the modern web: HTML for structure, CSS for presentation, and JavaScript for interactivity.",
                'prerequisites' => ['Basic computer literacy'],
                'learning_objectives' => [
                    'Build accessible, semantic HTML5 document structures',
                    'Style responsive multi-column layouts using CSS Flexbox and Grid',
                    'Handle user interaction events using vanilla JavaScript DOM APIs',
                    'Deploy static websites to live hosting platforms',
                ],
                'skills_gained' => ['HTML5 Semantic Structure', 'CSS3 Flexbox & Grid', 'Responsive Design', 'JavaScript DOM Manipulation', 'Web Accessibility'],
                'thumbnail' => 'https://images.unsplash.com/photo-1507238691740-187a5b1d37b8?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: HTML5 & Responsive CSS3',
                        'lessons' => [
                            ['title' => 'Semantic HTML5 Elements & Page Structure', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'CSS Box Model, Typography & Color Systems', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Responsive Layouts with CSS Flexbox & CSS Grid', 'type' => 'text', 'duration' => '30 min'],
                        ],
                    ],
                    [
                        'title' => 'Module 2: JavaScript Interactivity & Capstone',
                        'lessons' => [
                            ['title' => 'JavaScript Variables, Functions & DOM Selection', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Event Listeners & Form Validation', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Web Fundamentals Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Responsive Personal Portfolio Website', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],

            // Other Categories Basic
            [
                'title' => 'Software Testing Fundamentals',
                'slug' => 'software-testing-fundamentals',
                'category' => 'Quality Assurance & Testing',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 12999,
                'description' => 'Understand software quality assurance, the STLC lifecycle, test case design, black-box testing techniques, and bug reporting.',
                'full_description' => "Software Testing Fundamentals prepares future QA analysts to systematically discover software defects, write clear test cases, and assure software reliability.",
                'prerequisites' => ['Basic computer operations'],
                'learning_objectives' => [
                    'Understand SDLC vs STLC methodologies and QA lifecycle phases',
                    'Design boundary value and equivalence partitioning test cases',
                    'Write professional bug reports with reproducibility steps',
                    'Conduct functional, regression, and smoke testing passes',
                ],
                'skills_gained' => ['Manual Testing', 'Test Case Design', 'Bug Reporting', 'STLC Methodology', 'Defect Tracking'],
                'thumbnail' => 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: QA Process & Test Case Design',
                        'lessons' => [
                            ['title' => 'Software Testing Principles & STLC Overview', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'Test Case Authoring & Execution Techniques', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'QA Fundamentals Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Comprehensive Test Plan & Bug Report', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Mobile App Development Fundamentals',
                'slug' => 'mobile-app-development-fundamentals',
                'category' => 'Mobile Engineering',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 12999,
                'description' => 'Understand mobile OS architectures, touchscreen UI paradigms, mobile device constraints, and cross-platform mobile frameworks.',
                'full_description' => "Mobile App Development Fundamentals introduces the core concepts of mobile development across iOS and Android ecosystems.",
                'prerequisites' => ['Basic programming awareness'],
                'learning_objectives' => [
                    'Understand mobile application lifecycles and navigation patterns',
                    'Differentiate Native, Hybrid, and Cross-Platform mobile frameworks',
                    'Design touch-friendly mobile layouts and adaptive responsive screens',
                    'Understand mobile storage, offline caching, and app permissions',
                ],
                'skills_gained' => ['Mobile UI Design', 'App Lifecycle', 'Cross-Platform Concepts', 'Touch Gestures', 'Mobile Device APIs'],
                'thumbnail' => 'https://images.unsplash.com/photo-1512941937669-90a1b58e7e9c?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Mobile UI & Architecture',
                        'lessons' => [
                            ['title' => 'Mobile Architecture: iOS vs Android Foundations', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'Mobile Navigation & UI Patterns', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Mobile Fundamentals Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Interactive Mobile UI Prototype', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Graphic Design Fundamentals',
                'slug' => 'graphic-design-fundamentals',
                'category' => 'Design & Creative',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Elena Rostova',
                'price' => 11999,
                'description' => 'Learn color theory, typography, grid layouts, visual hierarchy, and composition principles for digital branding.',
                'full_description' => "Graphic Design Fundamentals teaches visual literacy, color harmonies, font pairings, negative space, and visual communication principles.",
                'prerequisites' => ['None'],
                'learning_objectives' => [
                    'Apply color harmonies and psychological associations in digital design',
                    'Select and pair typography effectively across digital media',
                    'Implement visual hierarchy and composition rules (Rule of Thirds, F-pattern)',
                    'Export vector and raster assets in optimal digital formats',
                ],
                'skills_gained' => ['Color Theory', 'Typography', 'Visual Hierarchy', 'Composition Rules', 'Digital Asset Formats'],
                'thumbnail' => 'https://images.unsplash.com/photo-1561070791-2526d30994b5?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Visual Composition & Color',
                        'lessons' => [
                            ['title' => 'Elements & Principles of Visual Design', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'Color Theory, Harmonies & Contrast Accessibility', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Typography Hierarchy & Font Pairing', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Graphic Design Fundamentals Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Brand Identity Style Guide', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'UI/UX Design Fundamentals',
                'slug' => 'ui-ux-design-fundamentals',
                'category' => 'Design & Creative',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Elena Rostova',
                'price' => 12999,
                'description' => 'Master user research basics, wireframing, Figma interface design, usability heuristics, and user journey mapping.',
                'full_description' => "UI/UX Design Fundamentals provides the complete starting path for aspiring product designers, covering empathetic user research and Figma wireframing.",
                'prerequisites' => ['Basic computer literacy'],
                'learning_objectives' => [
                    'Conduct foundational user interviews and create user personas',
                    'Map user journey flows and information architecture sitemaps',
                    'Build low-fidelity wireframes and high-fidelity Figma mockups',
                    'Evaluate interfaces against Nielsen’s 10 Usability Heuristics',
                ],
                'skills_gained' => ['User Research', 'Wireframing', 'Figma Basics', 'User Personas', 'Usability Heuristics'],
                'thumbnail' => 'https://images.unsplash.com/photo-1581291518857-4e27b48ff24e?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: User Experience Research & Wireframing',
                        'lessons' => [
                            ['title' => 'Introduction to UX Thinking & Design Sprints', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'Creating User Personas & Journey Maps', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Wireframing & Prototyping in Figma', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'UI/UX Fundamentals Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Mobile App Wireframe & User Flow Project', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Digital Marketing Fundamentals',
                'slug' => 'digital-marketing-fundamentals',
                'category' => 'Marketing & Business',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Kavita Menon',
                'price' => 11999,
                'description' => 'Learn digital marketing channels, audience segmentation, content marketing, search engine basics, and campaign funnels.',
                'full_description' => "Digital Marketing Fundamentals introduces the core organic and paid digital marketing channels to grow modern businesses online.",
                'prerequisites' => ['None'],
                'learning_objectives' => [
                    'Understand the customer acquisition funnel (Awareness, Consideration, Conversion)',
                    'Formulate organic content marketing and SEO keyword strategies',
                    'Understand paid advertising channels (Google Ads, Meta Ads, LinkedIn)',
                    'Measure digital marketing performance with key metrics (CTR, CPC, CPA, ROAS)',
                ],
                'skills_gained' => ['Digital Channels', 'Customer Funnels', 'SEO Basics', 'Paid Media Basics', 'Marketing Metrics'],
                'thumbnail' => 'https://images.unsplash.com/photo-1533750349088-cd871a92f312?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Marketing Funnels & Channels',
                        'lessons' => [
                            ['title' => 'Digital Marketing Overview & Channel Strategy', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'SEO & Content Marketing Fundamentals', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Digital Marketing Fundamentals Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Multi-Channel Marketing Campaign Plan', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'SAP Enterprise Fundamentals',
                'slug' => 'sap-enterprise-fundamentals',
                'category' => 'SAP',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 14999,
                'description' => 'Understand enterprise resource planning concepts, SAP GUI navigation, master data vs transactional data, and module interactions.',
                'full_description' => "SAP Enterprise Fundamentals provides a clear overview of enterprise ERP concepts, organization units, master data, and cross-functional business processes.",
                'prerequisites' => ['Basic business and spreadsheet literacy'],
                'learning_objectives' => [
                    'Understand Enterprise Resource Planning (ERP) architecture and history',
                    'Navigate the SAP GUI and Fiori Launchpad environments',
                    'Differentiate Master Data, Transactional Data, and Configuration Data',
                    'Understand core SAP modules (FICO, MM, SD, PP) and business integration points',
                ],
                'skills_gained' => ['SAP GUI Navigation', 'ERP Architecture', 'Master Data Management', 'Cross-Module Integration', 'Enterprise Structures'],
                'thumbnail' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: ERP Architecture & SAP Navigation',
                        'lessons' => [
                            ['title' => 'Introduction to ERP & SAP S/4HANA Ecosystem', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'SAP GUI, Fiori Interface & Navigation Essentials', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Master Data vs Transactional Data Architecture', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'SAP Enterprise Fundamentals Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise Organizational Structure Mapping', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Medical Terminology & Healthcare Fundamentals',
                'slug' => 'medical-terminology-healthcare-fundamentals',
                'category' => 'Healthcare & Life Sciences',
                'difficulty' => 'Basic',
                'duration' => '6 Weeks',
                'instructor' => 'Dr. R. K. Sharma',
                'price' => 11999,
                'description' => 'Understand medical root words, prefixes, suffixes, major body systems, diagnostic terms, and HIPAA healthcare compliance.',
                'full_description' => "Medical Terminology & Healthcare Fundamentals equips students with precise clinical terminology required for medical coding, health informatics, and clinical administration.",
                'prerequisites' => ['High school biology or basic health literacy'],
                'learning_objectives' => [
                    'Deconstruct complex clinical terms into prefixes, roots, and suffixes',
                    'Identify anatomical structures and physiological functions across organ systems',
                    'Understand standard diagnostic and procedural clinical documentation',
                    'Maintain patient data privacy compliance under HIPAA regulations',
                ],
                'skills_gained' => ['Medical Terminology', 'Human Anatomy', 'Clinical Documentation', 'HIPAA Privacy', 'Healthcare Systems'],
                'thumbnail' => 'https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Medical Word Structure & Organ Systems',
                        'lessons' => [
                            ['title' => 'Medical Word Construction: Roots, Prefixes & Suffixes', 'type' => 'video', 'duration' => '25 min'],
                            ['title' => 'Body Systems, Anatomy & Clinical Abbreviations', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Medical Terminology Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Clinical Record Terminology Extraction Report', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function getIntermediateCourses(): array
    {
        return [
            // Cyber Security Intermediate
            [
                'title' => 'Ethical Hacking',
                'slug' => 'ethical-hacking',
                'category' => 'Cyber Security',
                'difficulty' => 'Intermediate',
                'duration' => '12 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 24999,
                'description' => 'Comprehensive penetration testing and ethical hacking program covering reconnaissance, network scanning, vulnerability assessment, web application security (OWASP Top 10), and privilege escalation.',
                'full_description' => "Ethical Hacking provides complete, hands-on penetration testing training in controlled virtual lab environments. Learn the end-to-end security audit lifecycle from reconnaissance and vulnerability scanning to exploitation, privilege escalation, and executive reporting.\n\nAll exercises use authorized sandbox labs and intentionally vulnerable targets.",
                'prerequisites' => [
                    'Networking fundamentals (TCP/IP model, subnets, ports, DNS, HTTP/S)',
                    'Intermediate Linux command-line navigation and bash scripting basics',
                    'Fundamental understanding of web architectures and client-server protocols',
                ],
                'learning_objectives' => [
                    'Master reconnaissance methodologies using passive OSINT and active Nmap scanning',
                    'Conduct vulnerability assessments using OpenVAS/Nessus and calculate CVSS v3.1 scores',
                    'Exploit and defend against OWASP Top 10 vulnerabilities (SQLi, XSS, CSRF, RCE, IDOR)',
                    'Perform system exploitation using Metasploit and privilege escalation on Linux & Windows',
                    'Draft professional penetration testing reports with remediation roadmaps for executives',
                ],
                'skills_gained' => [
                    'Penetration Testing Methodology', 'Nmap & Network Scanning', 'Burp Suite Pro',
                    'OWASP Top 10 Exploitation & Defense', 'Metasploit Framework', 'Privilege Escalation',
                ],
                'thumbnail' => 'https://images.unsplash.com/photo-1526374965328-7f61d4dc18c5?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Cybersecurity & Ethical Hacking Fundamentals',
                        'description' => 'Core security concepts, CIA triad, threat modeling, rules of engagement, and ethical frameworks.',
                        'lessons' => [
                            ['title' => 'Introduction to Ethical Hacking & Scoping Rules', 'type' => 'video', 'duration' => '25 min', 'video_url' => 'https://www.youtube.com/watch?v=3Kq1MIfTWCE'],
                            ['title' => 'Threat Modeling, Attack Vectors & Risk Management', 'type' => 'text', 'duration' => '20 min'],
                            ['title' => 'Authorized Virtual Lab Environment Setup (Kali Linux & Target VMs)', 'type' => 'text', 'duration' => '30 min'],
                        ],
                    ],
                    [
                        'title' => 'Module 2: Linux & Networking for Security Professionals',
                        'description' => 'Deep dive into TCP/IP, OSI model, packet analysis with Wireshark, and Linux security CLI.',
                        'lessons' => [
                            ['title' => 'TCP/IP Handshakes, Ports & Protocol Inspection', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=n2D1o-aM-2s'],
                            ['title' => 'Wireshark Packet Capture & Network Analysis', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Essential Linux Commands & Bash Scripting for Auditing', 'type' => 'text', 'duration' => '30 min'],
                        ],
                    ],
                    [
                        'title' => 'Module 3: Reconnaissance, Scanning & Enumeration',
                        'description' => 'Passive and active reconnaissance, OSINT, Nmap port scanning, banner grabbing, and DNS enumeration.',
                        'lessons' => [
                            ['title' => 'Passive Reconnaissance & OSINT Frameworks', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=qwA6MmbeGNo'],
                            ['title' => 'Active Network Scanning with Nmap & Zenmap', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'Service Fingerprinting & Banner Grabbing', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Checkpoint Quiz: Reconnaissance & Network Auditing', 'type' => 'quiz', 'duration' => '20 min'],
                        ],
                    ],
                    [
                        'title' => 'Module 4: Vulnerability Assessment & Management',
                        'description' => 'Automated vulnerability scanners (OpenVAS, Nessus), CVSS scoring, and false positive identification.',
                        'lessons' => [
                            ['title' => 'Vulnerability Scanning Architecture & Tooling', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=ZfXjG9F7pI0'],
                            ['title' => 'CVSS v3.1 Metrics & Severity Prioritization', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Eliminating False Positives & Validation Workflows', 'type' => 'text', 'duration' => '25 min'],
                        ],
                    ],
                    [
                        'title' => 'Module 5: Web Application Security & OWASP Top 10',
                        'description' => 'Burp Suite proxy configuration, SQL Injection, Cross-Site Scripting, CSRF, and broken access controls.',
                        'lessons' => [
                            ['title' => 'Burp Suite Setup: Intercepting & Modifying HTTP Traffic', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=G3hpA5q8yB8'],
                            ['title' => 'SQL Injection (SQLi): Union-Based, Blind & Error-Based Exploitation', 'type' => 'text', 'duration' => '40 min'],
                            ['title' => 'Cross-Site Scripting (XSS): Reflected, Stored & DOM-Based', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Cross-Site Request Forgery (CSRF) & Broken Access Controls', 'type' => 'text', 'duration' => '30 min'],
                        ],
                    ],
                    [
                        'title' => 'Module 6: System Exploitation & Privilege Escalation',
                        'description' => 'Metasploit framework, payload generation, and Linux/Windows privilege escalation.',
                        'lessons' => [
                            ['title' => 'Metasploit Framework: Architecture, Exploit Modules & Payloads', 'type' => 'video', 'duration' => '45 min', 'video_url' => 'https://www.youtube.com/watch?v=fNZpcwA1B-A'],
                            ['title' => 'Linux Privilege Escalation: SUID, Sudo & Cron Misconfigurations', 'type' => 'text', 'duration' => '40 min'],
                            ['title' => 'Windows Privilege Escalation: Service Permissions & Token Stealing', 'type' => 'text', 'duration' => '40 min'],
                            ['title' => 'Password Cracking with Hashcat & John the Ripper', 'type' => 'text', 'duration' => '35 min'],
                        ],
                    ],
                    [
                        'title' => 'Module 7: Penetration Testing Workflow, Reporting & Capstone',
                        'description' => 'End-to-end security audit execution, executive summary writing, and final lab challenge.',
                        'lessons' => [
                            ['title' => 'Penetration Testing Workflow & Client Scoping', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'Drafting Professional Penetration Testing Reports', 'type' => 'text', 'duration' => '40 min'],
                            ['title' => 'Vulnerability Remediation & Defense-in-Depth Roadmaps', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'Final Capstone: Authorized Penetration Test & Security Audit Report', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Cyber Security',
                'slug' => 'cyber-security',
                'category' => 'Cyber Security',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 19999,
                'description' => 'Enterprise defensive and offensive security operations, SIEM log monitoring, incident response frameworks, and endpoint hardening.',
                'full_description' => "Cyber Security prepares practitioners for enterprise blue team and security operations roles, focusing on threat detection, SIEM configurations, firewall rules, and incident handling.",
                'prerequisites' => ['Cyber Security Fundamentals or equivalent network security knowledge'],
                'learning_objectives' => [
                    'Implement defense-in-depth architecture across enterprise networks',
                    'Configure and analyze security event logs using SIEM platforms',
                    'Execute incident handling following the NIST Incident Response Framework',
                    'Harden Windows Server and Linux enterprise endpoints against lateral movement',
                ],
                'skills_gained' => ['SIEM Configuration', 'Incident Response', 'Network Defense', 'Endpoint Hardening', 'Log Analysis'],
                'thumbnail' => 'https://images.unsplash.com/photo-1563986768609-322da13575f3?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Enterprise Security Architecture & Defense',
                        'lessons' => [
                            ['title' => 'Defense-in-Depth & Zero Trust Network Architecture', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'SIEM Log Ingestion & Threat Detection Rules', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'Incident Response Planning & Forensic Triage', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'Cyber Security Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise Incident Response Simulation', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Web Application Security',
                'slug' => 'web-application-security',
                'category' => 'Cyber Security',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 19999,
                'description' => 'Deep dive into OWASP Top 10 vulnerabilities, session management flaws, CORS misconfigurations, and secure code review practices.',
                'full_description' => "Web Application Security provides specialized training in auditing, exploiting, and securing modern web applications, Single Page Applications, and REST APIs.",
                'prerequisites' => ['Web Development Fundamentals', 'Basic HTTP protocol knowledge'],
                'learning_objectives' => [
                    'Perform hands-on analysis of OWASP Top 10 web vulnerabilities',
                    'Audit authentication, session tokens, JWTs, and CORS configurations',
                    'Conduct manual secure code reviews to catch business logic flaws',
                    'Implement defensive headers (CSP, HSTS, X-Frame-Options) and input validation sanitizers',
                ],
                'skills_gained' => ['OWASP Top 10 Auditing', 'Burp Suite', 'JWT Security', 'CORS/CSP Hardening', 'Secure Code Review'],
                'thumbnail' => 'https://images.unsplash.com/photo-1555949963-ff9fe0c870eb?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Web Vulnerability Identification & Defense',
                        'lessons' => [
                            ['title' => 'HTTP Message Inspection & Security Headers Configuration', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Deep Dive: Injection & Broken Access Controls', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Web App Security Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Full Security Assessment of a Web Application', 'type' => 'assignment', 'duration' => '60 min'],
                        ],
                    ],
                ],
            ],

            // Full Stack Intermediate
            [
                'title' => 'Full Stack Web Development',
                'slug' => 'full-stack-web-development',
                'category' => 'FULL STACK',
                'difficulty' => 'Intermediate',
                'duration' => '12 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 24999,
                'description' => 'Learn frontend, backend, databases, and APIs to build production-ready web applications using React, Node.js, and PostgreSQL.',
                'full_description' => "Full Stack Web Development covers modern single-page frontend engineering with React, server-side REST API development with Express/Node.js, PostgreSQL relational modeling, JWT authentication, and cloud deployment.",
                'prerequisites' => ['Web Development Fundamentals', 'JavaScript ES6 familiarity'],
                'learning_objectives' => [
                    'Build modular dynamic user interfaces with React, state hooks, and component composition',
                    'Architect robust backend REST APIs with Node.js, Express, and request validation',
                    'Model relational database tables and perform migrations with PostgreSQL',
                    'Implement secure authentication using JSON Web Tokens (JWT) and hashed passwords',
                    'Deploy full-stack web applications with continuous integration to cloud hosting',
                ],
                'skills_gained' => ['React.js', 'Node.js/Express', 'PostgreSQL', 'REST API Architecture', 'JWT Authentication', 'Full Stack Deployment'],
                'thumbnail' => 'https://images.unsplash.com/photo-1517694712202-14dd9538aa97?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: React Frontend Engineering',
                        'lessons' => [
                            ['title' => 'React Architecture: Components, Props & Hooks', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Managing Complex State with Context & Reducers', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'Client-Side Routing & HTTP Data Fetching', 'type' => 'text', 'duration' => '25 min'],
                        ],
                    ],
                    [
                        'title' => 'Module 2: Node.js Backend & Database APIs',
                        'lessons' => [
                            ['title' => 'REST API Design with Express & Middleware', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'PostgreSQL Schema Design & Relational Queries', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'JWT Authentication & Password Hashing', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Full Stack Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Build and Deploy a Full-Stack SaaS Web App', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Full Stack Development with AI',
                'slug' => 'full-stack-development-with-ai',
                'category' => 'FULL STACK',
                'difficulty' => 'Intermediate',
                'duration' => '12 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 24999,
                'description' => 'Build modern full-stack web applications supercharged with AI capabilities, LLM API integration, vector embeddings, and streaming responses.',
                'full_description' => "Full Stack Development with AI teaches software engineers to integrate AI models into web applications: streaming AI responses, semantic search with vector databases, automated code generation, and intelligent user experiences.",
                'prerequisites' => ['Full Stack Web Development or solid React + backend fundamentals'],
                'learning_objectives' => [
                    'Integrate OpenAI and open-source LLM APIs into React and Node backends',
                    'Implement streaming tokens using Server-Sent Events (SSE) and WebSockets',
                    'Store and query high-dimensional embeddings using pgvector and Pinecone',
                    'Build conversational chat interfaces and AI copilot widgets in React',
                ],
                'skills_gained' => ['Full Stack AI', 'LLM Integration', 'Streaming APIs (SSE)', 'Vector Search (pgvector)', 'React AI UI Patterns'],
                'thumbnail' => 'https://images.unsplash.com/photo-1677442136019-21780efad99a?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: AI Integration in Modern Web Stacks',
                        'lessons' => [
                            ['title' => 'LLM API Integration & Secure Server-Side Key Management', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Streaming Responses & Real-Time Token Generation in React', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'Vector Search with PostgreSQL & pgvector', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'Full Stack AI Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Full Stack AI Knowledgebase & Assistant SaaS', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],

            // AI / Python Intermediate
            [
                'title' => 'Python With AI',
                'slug' => 'python-with-ai',
                'category' => 'ARTIFICIAL INTELLIGENCE',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Dr. Aris Thorne',
                'price' => 19999,
                'description' => 'Master Python for artificial intelligence: asynchronous APIs, LangChain orchestration, vector databases, and AI application architectures.',
                'full_description' => "Python With AI teaches developers to harness Python 3.12, AsyncIO, LangChain, and OpenAI APIs to build intelligent automation pipelines and intelligent agents.",
                'prerequisites' => ['Python Fundamentals or equivalent programming experience'],
                'learning_objectives' => [
                    'Write high-performance asynchronous Python with AsyncIO and httpx',
                    'Orchestrate multi-step LLM chains with LangChain and LangGraph',
                    'Implement semantic document search with ChromaDB and FAISS vector stores',
                    'Build interactive AI applications using Streamlit and FastAPI',
                ],
                'skills_gained' => ['Python 3.12', 'LangChain', 'Vector Databases', 'AsyncIO', 'FastAPI for AI'],
                'thumbnail' => 'https://images.unsplash.com/photo-1526379095098-d400fd0bf935?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Advanced Python & AI Orchestration',
                        'lessons' => [
                            ['title' => 'Asynchronous Python Programming for AI Workflows', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'LangChain Architecture: Prompts, Chains & Memory', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'Vector Indexing & Semantic Search in Python', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'Python With AI Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Python-Powered Autonomous Research Assistant', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Machine Learning',
                'slug' => 'machine-learning',
                'category' => 'ARTIFICIAL INTELLIGENCE',
                'difficulty' => 'Intermediate',
                'duration' => '12 Weeks',
                'instructor' => 'Dr. Aris Thorne',
                'price' => 22999,
                'description' => 'Implement supervised and unsupervised machine learning algorithms using Scikit-Learn, feature engineering, and cross-validation pipelines.',
                'full_description' => "Machine Learning covers linear regression, logistic regression, decision trees, random forests, support vector machines, clustering, hyperparameter tuning, and model evaluation metrics.",
                'prerequisites' => ['Python Fundamentals', 'Basic linear algebra & statistics'],
                'learning_objectives' => [
                    'Engineer predictive features and handle categorical/numerical data transformations',
                    'Train and tune supervised classification and regression algorithms',
                    'Implement unsupervised clustering with K-Means and Hierarchical Clustering',
                    'Evaluate models with Precision, Recall, F1-Score, ROC-AUC, and Cross-Validation',
                ],
                'skills_gained' => ['Scikit-Learn', 'Feature Engineering', 'Supervised Learning', 'Clustering', 'Model Evaluation'],
                'thumbnail' => 'https://images.unsplash.com/photo-1555949963-aa79dcee981c?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Supervised & Unsupervised Learning Workflows',
                        'lessons' => [
                            ['title' => 'Feature Engineering & Data Preprocessing Pipelines', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Classification Algorithms: Trees, Forests & SVMs', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Unsupervised Clustering & Dimensionality Reduction', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'Machine Learning Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: End-to-End Customer Churn Prediction ML System', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Generative AI',
                'slug' => 'generative-ai',
                'category' => 'ARTIFICIAL INTELLIGENCE',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Dr. Aris Thorne',
                'price' => 24999,
                'description' => 'Master prompt engineering, Retrieval-Augmented Generation (RAG), vector embeddings, and LLM application development.',
                'full_description' => "Generative AI teaches developers how to build production RAG systems, connect private company documents to LLMs, format structured JSON outputs, and manage hallucination guardrails.",
                'prerequisites' => ['Python Fundamentals', 'Basic API interaction familiarity'],
                'learning_objectives' => [
                    'Master advanced prompt engineering (Few-Shot, Chain-of-Thought, ReAct)',
                    'Build end-to-end Retrieval-Augmented Generation (RAG) pipelines',
                    'Implement semantic chunking and hybrid dense/sparse vector retrieval',
                    'Evaluate RAG performance with groundedness and answer relevance metrics',
                ],
                'skills_gained' => ['Prompt Engineering', 'RAG Pipelines', 'Vector Databases', 'LlamaIndex', 'Hallucination Mitigation'],
                'thumbnail' => 'https://images.unsplash.com/photo-1620712943543-bcc4688e7485?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Production RAG Architectures',
                        'lessons' => [
                            ['title' => 'Prompt Engineering Patterns & Structured Output Generation', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Chunking Strategies, Embeddings & Vector Search', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'RAG Pipeline Implementation with LangChain & LlamaIndex', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Generative AI Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise Document Q&A RAG Platform', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],

            // Data Intermediate
            [
                'title' => 'Data Science',
                'slug' => 'data-science',
                'category' => 'DATA SCIENCE',
                'difficulty' => 'Intermediate',
                'duration' => '12 Weeks',
                'instructor' => 'Dr. Aris Thorne',
                'price' => 22999,
                'description' => 'Comprehensive data science program covering statistical inference, exploratory data analysis, predictive modeling, and business insights.',
                'full_description' => "Data Science combines statistical rigor, data engineering, and machine learning to extract actionable intelligence from complex enterprise datasets.",
                'prerequisites' => ['Data Science Fundamentals or Python programming experience'],
                'learning_objectives' => [
                    'Perform rigorous exploratory data analysis and hypothesis validation',
                    'Build regression and classification models with Scikit-Learn',
                    'Optimize model hyperparameters and detect statistical data drift',
                    'Communicate actionable analytical insights to executive stakeholders',
                ],
                'skills_gained' => ['Data Science Pipeline', 'Statistical Inference', 'Scikit-Learn', 'Feature Engineering', 'Model Interpretation'],
                'thumbnail' => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Statistical Modeling & Data Science Pipeline',
                        'lessons' => [
                            ['title' => 'Exploratory Data Analysis & Statistical Inference', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Predictive Modeling & Feature Selection', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Data Science Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Real-World Predictive Analytics Project', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Data Science with Python',
                'slug' => 'data-science-with-python',
                'category' => 'DATA SCIENCE',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Dr. Aris Thorne',
                'price' => 19999,
                'description' => 'Master NumPy, Pandas, Matplotlib, Seaborn, and Scikit-Learn for end-to-end data manipulation and predictive modeling in Python.',
                'full_description' => "Data Science with Python focuses on mastering the PyData ecosystem to clean, transform, visualize, and model structured and unstructured data.",
                'prerequisites' => ['Python Fundamentals'],
                'learning_objectives' => [
                    'Master complex tabular transformations in Pandas (groupby, pivot_table, merge)',
                    'Visualize multi-variable data distributions with Seaborn and Plotly',
                    'Train supervised machine learning models in Scikit-Learn',
                ],
                'skills_gained' => ['Pandas Mastery', 'NumPy Vectorization', 'Interactive Plotting', 'Scikit-Learn', 'Statistical Analysis'],
                'thumbnail' => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: PyData Ecosystem Mastery',
                        'lessons' => [
                            ['title' => 'Advanced Pandas Indexing, Merging & Aggregation', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Statistical Visualization & Interactive Plotting', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'Data Science with Python Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Multi-Variate Financial Risk Analysis', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Data Analyst',
                'slug' => 'data-analyst',
                'category' => 'DATA ANALYST',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 19999,
                'description' => 'Learn SQL querying, Power BI dashboard design, statistical analysis, and business intelligence reporting for decision-making.',
                'full_description' => "Data Analyst prepares professionals to translate business questions into actionable insights through advanced SQL, Power BI DAX modeling, and executive KPI dashboards.",
                'prerequisites' => ['Data Analysis Fundamentals or basic SQL/Excel knowledge'],
                'learning_objectives' => [
                    'Write complex multi-table SQL queries with CTEs and Window Functions',
                    'Build interactive Power BI dashboards with DAX measures and drill-throughs',
                    'Identify business trends and formulate data-backed recommendations',
                ],
                'skills_gained' => ['Advanced SQL', 'Power BI & DAX', 'Data Visualization', 'Business KPI Tracking', 'Data Storytelling'],
                'thumbnail' => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Business Intelligence & SQL Analytics',
                        'lessons' => [
                            ['title' => 'Complex SQL for Business Analytics & Window Functions', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Power BI Data Modeling, Relationships & DAX Calculations', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Data Analyst Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Executive Revenue & Operations BI Dashboard', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Data Analyst with SQL & Power BI',
                'slug' => 'data-analyst-with-sql-power-bi',
                'category' => 'DATA ANALYST',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 19999,
                'description' => 'Hands-on training combining advanced SQL data extraction with enterprise Power BI reporting and DAX calculations.',
                'full_description' => "Data Analyst with SQL & Power BI bridges the technical gap between raw relational databases and polished executive Power BI presentations.",
                'prerequisites' => ['SQL Fundamentals'],
                'learning_objectives' => [
                    'Write advanced analytical SQL (Window functions, Common Table Expressions, Subqueries)',
                    'Model star and snowflake schemas in Power BI Desktop',
                    'Write complex DAX measures (CALCULATE, FILTER, Time Intelligence)',
                ],
                'skills_gained' => ['SQL Analytics', 'Power BI Desktop', 'DAX Measures', 'Star Schemas', 'Executive Reporting'],
                'thumbnail' => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: SQL to Power BI Enterprise Workflow',
                        'lessons' => [
                            ['title' => 'Advanced SQL Queries for Data Analysts', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Power BI Data Modeling & Time Intelligence DAX', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'SQL & Power BI Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise Sales & Performance BI Dashboard', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Business Intelligence',
                'slug' => 'business-intelligence',
                'category' => 'DATA ANALYST',
                'difficulty' => 'Intermediate',
                'duration' => '8 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 17999,
                'description' => 'Master enterprise BI reporting, dimensional data modeling, KPI tracking, and interactive dashboard architectures.',
                'full_description' => "Business Intelligence covers dimensional modeling (Kimball methodology), semantic layers, ETL automation, and multi-dimensional reporting.",
                'prerequisites' => ['Data Analysis Fundamentals'],
                'learning_objectives' => [
                    'Design dimensional star schemas with fact and dimension tables',
                    'Define and track operational and strategic enterprise KPIs',
                    'Automate data refreshes and publish secure reports to BI portals',
                ],
                'skills_gained' => ['Dimensional Modeling', 'KPI Architecture', 'Tableau / Power BI', 'Data Warehousing Basics', 'Semantic Layers'],
                'thumbnail' => 'https://images.unsplash.com/photo-1551836022-d5d88e9218df?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Dimensional Modeling & BI Strategy',
                        'lessons' => [
                            ['title' => 'Dimensional Modeling: Facts, Dimensions & Grain', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Enterprise KPI Design & Dashboard UX', 'type' => 'text', 'duration' => '25 min'],
                            ['title' => 'Business Intelligence Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Corporate BI Strategy & Dashboard Suite', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Data Engineering',
                'slug' => 'data-engineering',
                'category' => 'DATA ENGINEERING',
                'difficulty' => 'Intermediate',
                'duration' => '12 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 24999,
                'description' => 'Build scalable data pipelines, automated ETL workflows, Apache Airflow DAGs, and cloud data warehouses with Python and SQL.',
                'full_description' => "Data Engineering teaches students to build robust data infrastructure: data extraction from APIs/databases, transformations with Pandas/PySpark, orchestration with Airflow, and warehousing in Snowflake/BigQuery.",
                'prerequisites' => ['Data Engineering Fundamentals or strong Python + SQL background'],
                'learning_objectives' => [
                    'Design resilient ETL/ELT pipelines with Python and SQL',
                    'Orchestrate complex DAG workflows using Apache Airflow',
                    'Process large datasets using Apache Spark and PySpark DataFrames',
                    'Model dimensional data warehouses in Snowflake and Google BigQuery',
                ],
                'skills_gained' => ['Apache Airflow', 'PySpark', 'ETL/ELT Pipelines', 'Snowflake / BigQuery', 'Data Modeling'],
                'thumbnail' => 'https://images.unsplash.com/photo-1558494949-ef010cbdcc31?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Pipeline Orchestration & Distributed Data',
                        'lessons' => [
                            ['title' => 'Building Robust ETL Pipelines with Python & SQL', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Apache Airflow: DAG Authoring & Scheduling', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Distributed Data Processing with PySpark', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Data Engineering Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Automated End-to-End Cloud Data Pipeline', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Data Engineering with Python',
                'slug' => 'data-engineering-with-python',
                'category' => 'DATA ENGINEERING',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 21999,
                'description' => 'Specialized Python data engineering covering PySpark, SQLAlchemy, API data extraction, Parquet file optimization, and Airflow orchestration.',
                'full_description' => "Data Engineering with Python focuses on leveraging Python's rich data ecosystem to ingest, validate, transform, and load massive data volumes reliably.",
                'prerequisites' => ['Python Fundamentals', 'SQL Fundamentals'],
                'learning_objectives' => [
                    'Write production Python ETL scripts with SQLAlchemy and psycopg2',
                    'Process and optimize columnar data storage with PyArrow and Parquet',
                    'Implement data quality validation with Great Expectations in Python',
                ],
                'skills_gained' => ['Python ETL', 'PySpark', 'SQLAlchemy', 'Airflow', 'Data Quality Testing'],
                'thumbnail' => 'https://images.unsplash.com/photo-1558494949-ef010cbdcc31?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Python Pipeline Engineering',
                        'lessons' => [
                            ['title' => 'Python Data Extraction & Database Streaming', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'PySpark DataFrame Transformations & Windowing', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Data Engineering with Python Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Production Python Data Ingestion Service', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],

            // Database Intermediate
            [
                'title' => 'SQL DBA',
                'slug' => 'sql-dba',
                'category' => 'DATABASE',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Aman Verma',
                'price' => 19999,
                'description' => 'Database administration, backup and recovery strategies, index maintenance, AlwaysOn High Availability, and security hardening.',
                'full_description' => "SQL DBA provides practical training for SQL Server database administrators: installing, configuring, disaster recovery, AlwaysOn availability groups, and performance monitoring.",
                'prerequisites' => ['SQL Fundamentals', 'Basic operating system administration knowledge'],
                'learning_objectives' => [
                    'Implement full, differential, and transaction log backup strategies',
                    'Configure AlwaysOn High Availability Groups and failover clustering',
                    'Analyze index fragmentation and rebuild/reorganize indexes',
                    'Secure database instances with logins, roles, and encryption',
                ],
                'skills_gained' => ['Database Administration', 'Backup & Disaster Recovery', 'AlwaysOn High Availability', 'Index Management', 'DB Security'],
                'thumbnail' => 'https://images.unsplash.com/photo-1544383835-bda2bc66a55d?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: SQL Server Administration & High Availability',
                        'lessons' => [
                            ['title' => 'SQL Server Architecture & Storage Engine', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Backup & Restore Strategies for Zero Data Loss', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'AlwaysOn Availability Groups & Disaster Recovery', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'SQL DBA Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise SQL Server Disaster Recovery Plan', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'SQL Boot Camp',
                'slug' => 'sql-boot-camp',
                'category' => 'DATABASE',
                'difficulty' => 'Intermediate',
                'duration' => '8 Weeks',
                'instructor' => 'Aman Verma',
                'price' => 17999,
                'description' => 'Intensive SQL bootcamp covering complex joins, Common Table Expressions, Window Functions, transactions, and indexing strategies.',
                'full_description' => "SQL Boot Camp takes your SQL skills from basic queries to expert data manipulation: CTEs, recursive queries, ranking functions, analytical aggregates, and transaction controls.",
                'prerequisites' => ['SQL Fundamentals'],
                'learning_objectives' => [
                    'Write complex multi-level subqueries and Common Table Expressions (CTEs)',
                    'Master analytical window functions (ROW_NUMBER, RANK, DENSE_RANK, NTILE, LAG, LEAD)',
                    'Implement transactional integrity with COMMIT, ROLLBACK, and isolation levels',
                ],
                'skills_gained' => ['Complex Joins', 'Window Functions', 'CTEs & Recursion', 'Transactions (ACID)', 'Query Optimization'],
                'thumbnail' => 'https://images.unsplash.com/photo-1544383835-bda2bc66a55d?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Advanced SQL & Analytical Operations',
                        'lessons' => [
                            ['title' => 'Deep Dive: Multi-Table Joins & Subquery Strategies', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Analytical Window Functions & Ranking Partitions', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'SQL Boot Camp Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Comprehensive Financial Dataset SQL Audit', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'SQL Server',
                'slug' => 'sql-server',
                'category' => 'DATABASE',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Aman Verma',
                'price' => 19999,
                'description' => 'T-SQL programming, stored procedures, user-defined functions, DML/DDL triggers, execution plans, and transaction locking.',
                'full_description' => "SQL Server teaches Microsoft T-SQL development: creating stored procedures, triggers, views, indexing strategies, and reading graphical execution plans.",
                'prerequisites' => ['SQL Fundamentals'],
                'learning_objectives' => [
                    'Write stored procedures and user-defined functions with input/output parameters',
                    'Implement DML and DDL triggers for audit logging and data enforcement',
                    'Analyze graphical execution plans to identify table scans and missing indexes',
                ],
                'skills_gained' => ['T-SQL Programming', 'Stored Procedures', 'Triggers & Views', 'Execution Plans', 'Locking & Concurrency'],
                'thumbnail' => 'https://images.unsplash.com/photo-1544383835-bda2bc66a55d?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: T-SQL Programmability & Performance',
                        'lessons' => [
                            ['title' => 'Stored Procedures, Parameters & Error Handling', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Triggers, Views & Table-Valued Functions', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'SQL Server Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise T-SQL Business Logic Engine', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'MongoDB',
                'slug' => 'mongodb',
                'category' => 'DATABASE',
                'difficulty' => 'Intermediate',
                'duration' => '8 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 17999,
                'description' => 'NoSQL document modeling, JSON/BSON schemas, Aggregation Pipeline, indexing, replica sets, and Node.js Mongoose integration.',
                'full_description' => "MongoDB provides complete training in document-oriented NoSQL databases: embedding vs referencing, high-throughput aggregation pipelines, indexes, and sharding.",
                'prerequisites' => ['Database Fundamentals or JavaScript familiarity'],
                'learning_objectives' => [
                    'Model document schemas using embedding and referencing patterns',
                    'Write complex multi-stage queries using the MongoDB Aggregation Pipeline',
                    'Build compound, text, and geospatial indexes for fast query execution',
                    'Integrate MongoDB with Node.js and Mongoose ODM in web applications',
                ],
                'skills_gained' => ['NoSQL Document Modeling', 'Aggregation Pipeline', 'Mongoose ODM', 'Indexing & Sharding', 'MongoDB Atlas'],
                'thumbnail' => 'https://images.unsplash.com/photo-1544383835-bda2bc66a55d?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Document Modeling & Aggregations',
                        'lessons' => [
                            ['title' => 'Document Schema Design: Embedding vs Referencing', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'The Aggregation Pipeline: Match, Group, Project & Unwind', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'MongoDB Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: High-Throughput E-Commerce MongoDB Backend', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Oracle PL/SQL',
                'slug' => 'oracle-pl-sql',
                'category' => 'DATABASE',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Aman Verma',
                'price' => 19999,
                'description' => 'Oracle procedural programming: anonymous blocks, procedures, functions, packages, cursors, collections, and dynamic SQL.',
                'full_description' => "Oracle PL/SQL teaches high-performance procedural extensions to Oracle SQL: cursor FOR loops, ref cursors, packages, bulk collect, and transaction management.",
                'prerequisites' => ['SQL Fundamentals'],
                'learning_objectives' => [
                    'Write structured PL/SQL anonymous blocks and exception handlers',
                    'Package procedures and functions with private and public specifications',
                    'Optimize data processing with BULK COLLECT and FORALL batch operations',
                ],
                'skills_gained' => ['Oracle PL/SQL', 'Packages & Stored Logic', 'Bulk Processing', 'Cursors & Collections', 'Database Triggers'],
                'thumbnail' => 'https://images.unsplash.com/photo-1544383835-bda2bc66a55d?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Procedural Programming & Packages',
                        'lessons' => [
                            ['title' => 'PL/SQL Architecture, Cursors & Exception Handling', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Packages, Collections & Bulk Processing (BULK COLLECT)', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Oracle PL/SQL Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise Banking Transaction PL/SQL Engine', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Oracle DBA',
                'slug' => 'oracle-dba',
                'category' => 'DATABASE',
                'difficulty' => 'Intermediate',
                'duration' => '12 Weeks',
                'instructor' => 'Aman Verma',
                'price' => 24999,
                'description' => 'Oracle database administration, Multitenant CDB/PDB architecture, RMAN backup and recovery, Data Guard, and storage management.',
                'full_description' => "Oracle DBA covers administration of enterprise Oracle databases: managing tablespaces, undo retention, redo logs, pluggable databases, RMAN backups, and Data Guard replication.",
                'prerequisites' => ['Database Fundamentals', 'Linux Fundamentals'],
                'learning_objectives' => [
                    'Administer Oracle Multitenant Container and Pluggable Databases (CDB/PDB)',
                    'Configure RMAN for automated hot/cold backups and point-in-time recovery',
                    'Manage tablespaces, datafiles, redo logs, and Automated Storage Management (ASM)',
                ],
                'skills_gained' => ['Oracle Database Admin', 'Multitenant (CDB/PDB)', 'RMAN Backup & Recovery', 'Data Guard Basics', 'Tablespace Management'],
                'thumbnail' => 'https://images.unsplash.com/photo-1544383835-bda2bc66a55d?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Oracle Database Architecture & Maintenance',
                        'lessons' => [
                            ['title' => 'Oracle Instance & Multitenant CDB/PDB Architecture', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'RMAN Backup, Recovery & Point-in-Time Restoration', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Oracle DBA Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise Oracle High-Availability Configuration', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],

            // Cloud Intermediate
            [
                'title' => 'AWS Cloud Engineering',
                'slug' => 'aws-cloud-engineering',
                'category' => 'CLOUD COMPUTING',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 21999,
                'description' => 'Deploy scalable cloud architectures on AWS: EC2, VPC networking, S3, IAM policies, RDS databases, Lambda, and CloudWatch monitoring.',
                'full_description' => "AWS Cloud Engineering provides hands-on training to design, deploy, and manage secure AWS cloud infrastructure following AWS Well-Architected guidelines.",
                'prerequisites' => ['Cloud Computing Fundamentals', 'Linux Fundamentals'],
                'learning_objectives' => [
                    'Architect custom Virtual Private Clouds (VPC) with public/private subnets and NAT',
                    'Configure EC2 instances with Auto Scaling Groups and Application Load Balancers',
                    'Manage IAM security policies, roles, and multi-factor authentication',
                    'Deploy serverless microservices with AWS Lambda and API Gateway',
                ],
                'skills_gained' => ['AWS VPC & EC2', 'AWS IAM Security', 'S3 & RDS', 'AWS Lambda Serverless', 'CloudWatch Monitoring'],
                'thumbnail' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Core AWS Infrastructure & Serverless',
                        'lessons' => [
                            ['title' => 'VPC Networking, Subnets & Routing Tables', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'EC2 Auto Scaling, Load Balancing & IAM Security', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Serverless Architecture with AWS Lambda & API Gateway', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'AWS Cloud Engineering Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Resilient 3-Tier Web Application on AWS', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Azure Cloud Engineering',
                'slug' => 'azure-cloud-engineering',
                'category' => 'CLOUD COMPUTING',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 21999,
                'description' => 'Deploy enterprise cloud solutions on Microsoft Azure: Virtual Machines, VNet, Entra ID (Azure AD), App Services, and Azure SQL.',
                'full_description' => "Azure Cloud Engineering prepares engineers to configure, monitor, and maintain Microsoft Azure cloud resources for enterprise production workloads.",
                'prerequisites' => ['Cloud Computing Fundamentals', 'Basic Windows/Linux administration'],
                'learning_objectives' => [
                    'Configure Azure Virtual Networks (VNet), Subnets, and Network Security Groups',
                    'Manage identity and access governance using Microsoft Entra ID',
                    'Deploy scalable web applications to Azure App Services and Azure Functions',
                ],
                'skills_gained' => ['Azure VNet & VMs', 'Microsoft Entra ID', 'Azure App Services', 'Azure SQL Database', 'Azure Resource Manager'],
                'thumbnail' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Enterprise Azure Infrastructure',
                        'lessons' => [
                            ['title' => 'Azure Networking, NSGs & Virtual Machine Deployment', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Identity Governance with Microsoft Entra ID & RBAC', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'Azure Cloud Engineering Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Scalable Enterprise Application on Azure', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'DevOps Engineering',
                'slug' => 'devops-engineering',
                'category' => 'CLOUD COMPUTING',
                'difficulty' => 'Intermediate',
                'duration' => '12 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 24999,
                'description' => 'Automate software delivery pipelines: Docker containerization, CI/CD with GitHub Actions, Terraform Infrastructure as Code, and Kubernetes.',
                'full_description' => "DevOps Engineering bridges software development and IT operations through automated testing, continuous integration, containerization, and infrastructure as code.",
                'prerequisites' => ['Linux Fundamentals', 'Git & GitHub Fundamentals'],
                'learning_objectives' => [
                    'Containerize applications with Docker and multi-stage Dockerfiles',
                    'Build automated CI/CD pipelines using GitHub Actions and Jenkins',
                    'Provision cloud infrastructure declaratively using Terraform HCL',
                    'Deploy and manage containerized workloads in Kubernetes clusters',
                ],
                'skills_gained' => ['Docker Containers', 'CI/CD Pipelines', 'Terraform (IaC)', 'Kubernetes Basics', 'DevOps Monitoring'],
                'thumbnail' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Containerization & CI/CD Pipelines',
                        'lessons' => [
                            ['title' => 'Docker Fundamentals: Images, Containers & Volumes', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'CI/CD Automation with GitHub Actions Workflows', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Infrastructure as Code with Terraform', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'DevOps Engineering Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Full Automated CI/CD Pipeline & Cloud Deployment', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Salesforce Administration',
                'slug' => 'salesforce-administration',
                'category' => 'CLOUD COMPUTING',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Pooja Sharma',
                'price' => 19999,
                'description' => 'Salesforce data modeling, automation with Flow Builder, security sharing rules, Reports & Dashboards, and Lightning App Builder.',
                'full_description' => "Salesforce Administration provides hands-on expertise to configure, manage, and customize enterprise CRM instances on the Salesforce Lightning platform.",
                'prerequisites' => ['Cloud Computing Fundamentals or CRM basic awareness'],
                'learning_objectives' => [
                    'Design custom objects, fields, and relationship models in Salesforce',
                    'Automate complex business processes using Salesforce Flow Builder',
                    'Configure organization-wide security, profiles, roles, and sharing rules',
                ],
                'skills_gained' => ['Salesforce Lightning', 'Flow Builder', 'Data Modeling', 'Security & Sharing', 'Reports & Dashboards'],
                'thumbnail' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Salesforce Data Architecture & Process Automation',
                        'lessons' => [
                            ['title' => 'Custom Objects, Fields & Relationship Modeling', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Business Process Automation with Flow Builder', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Salesforce Admin Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Custom Enterprise CRM Application on Salesforce', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Dell Boomi',
                'slug' => 'dell-boomi',
                'category' => 'CLOUD COMPUTING',
                'difficulty' => 'Intermediate',
                'duration' => '8 Weeks',
                'instructor' => 'Pooja Sharma',
                'price' => 17999,
                'description' => 'Enterprise integration platform (iPaaS): AtomSphere, process design, application connectors, data mapping, and API management.',
                'full_description' => "Dell Boomi covers cloud-based application integration using Boomi AtomSphere: building integration processes, data transformations, and error handling.",
                'prerequisites' => ['Cloud Computing Fundamentals', 'Basic API / JSON concepts'],
                'learning_objectives' => [
                    'Build and test integration processes in Boomi AtomSphere',
                    'Configure connectors for REST, SOAP, databases, and Salesforce',
                    'Implement data mapping, profile definitions, and business rule branches',
                ],
                'skills_gained' => ['Boomi AtomSphere', 'iPaaS Integration', 'Data Mapping', 'Connector Configuration', 'API Management'],
                'thumbnail' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Boomi Process Design & Integration',
                        'lessons' => [
                            ['title' => 'Boomi AtomSphere Architecture & Connector Design', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Data Mapping, Transformations & Error Trapping', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'Dell Boomi Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Cloud CRM to Database Integration Flow', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'ServiceNow',
                'slug' => 'servicenow',
                'category' => 'CLOUD COMPUTING',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Pooja Sharma',
                'price' => 19999,
                'description' => 'IT Service Management (ITSM), Incident/Change/Problem workflows, Business Rules, Client Scripts, Flow Designer, and Service Portal.',
                'full_description' => "ServiceNow teaches administration and configuration of the ServiceNow enterprise cloud platform: table schemas, business rules, client scripts, and service catalog workflows.",
                'prerequisites' => ['JavaScript basics or IT Essentials'],
                'learning_objectives' => [
                    'Configure ITSM applications (Incident, Problem, Change, Service Catalog)',
                    'Write Client Scripts and Server-Side Business Rules in JavaScript',
                    'Design automated multi-stage approvals using Flow Designer',
                ],
                'skills_gained' => ['ServiceNow ITSM', 'Business Rules', 'Client Scripts', 'Flow Designer', 'Service Portal'],
                'thumbnail' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: ServiceNow Administration & Scripting',
                        'lessons' => [
                            ['title' => 'ServiceNow Tables, Forms & Core ITSM Modules', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Business Rules, Client Scripts & Flow Designer Workflows', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'ServiceNow Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Custom Enterprise Service Desk Application', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Cloud & DevOps Engineering Mastery',
                'slug' => 'cloud-devops-engineering-mastery',
                'category' => 'CLOUD COMPUTING',
                'difficulty' => 'Intermediate',
                'duration' => '12 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 24999,
                'description' => 'Master AWS, Azure, Docker, Kubernetes, CI/CD pipelines, and Infrastructure as Code.',
                'full_description' => "Cloud & DevOps Engineering Mastery provides a comprehensive roadmap across multi-cloud infrastructure, containerization, orchestration, and automated pipelines.",
                'prerequisites' => ['Cloud Computing Fundamentals', 'Linux Fundamentals'],
                'learning_objectives' => [
                    'Deploy multi-tier applications across AWS and Azure cloud platforms',
                    'Build production Docker containers and manage Kubernetes workloads',
                    'Implement continuous deployment pipelines with monitoring and alerting',
                ],
                'skills_gained' => ['Multi-Cloud', 'Docker & Kubernetes', 'CI/CD Pipelines', 'Infrastructure as Code', 'Cloud Monitoring'],
                'thumbnail' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Multi-Cloud Infrastructure & CI/CD',
                        'lessons' => [
                            ['title' => 'Multi-Cloud Architecture & Container Workloads', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Kubernetes Pods, Services & Deployments', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Cloud & DevOps Mastery Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Multi-Cloud Automated Microservices Cluster', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],

            // SAP Intermediate
            [
                'title' => 'SAP FICO/HANA',
                'slug' => 'sap-fico',
                'category' => 'SAP',
                'difficulty' => 'Intermediate',
                'duration' => '12 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 24999,
                'description' => 'General Ledger, Accounts Payable, Accounts Receivable, Asset Accounting, Cost Center Accounting, and SAP S/4HANA financial reporting.',
                'full_description' => "SAP FICO/HANA provides comprehensive functional consultant training for SAP Financial Accounting (FI) and Controlling (CO) on S/4HANA.",
                'prerequisites' => ['SAP Enterprise Fundamentals or accounting background'],
                'learning_objectives' => [
                    'Configure General Ledger (FI-GL), Accounts Payable (FI-AP), and Accounts Receivable (FI-AR)',
                    'Set up Asset Accounting (FI-AA) and depreciation keys',
                    'Configure Cost Center Accounting (CO-CCA) and profit centers',
                ],
                'skills_gained' => ['SAP FI (GL/AP/AR)', 'SAP CO (Cost Centers)', 'Asset Accounting', 'S/4HANA Finance', 'Financial Statements'],
                'thumbnail' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: SAP Financial Accounting & Controlling',
                        'lessons' => [
                            ['title' => 'General Ledger, Chart of Accounts & Posting Periods', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Accounts Payable, Accounts Receivable & Asset Accounting', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Controlling & Cost Center Hierarchy Configuration', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'SAP FICO Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: End-to-End Company Code Financial Configuration', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'SAP MM',
                'slug' => 'sap-mm',
                'category' => 'SAP',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 21999,
                'description' => 'Materials Management, Procurement cycle (P2P), Purchase Requisitions, Purchase Orders, Goods Receipt, and Invoice Verification.',
                'full_description' => "SAP MM teaches configuration and execution of the Procure-to-Pay (P2P) cycle: vendor master records, material master records, purchasing documents, and inventory management.",
                'prerequisites' => ['SAP Enterprise Fundamentals'],
                'learning_objectives' => [
                    'Configure organizational structures for procurement (Plants, Storage Locations, Purchasing Orgs)',
                    'Manage material master records, vendor masters, and purchasing info records',
                    'Execute complete Procure-to-Pay cycles from PR to Invoice Verification (MIGO, MIRO)',
                ],
                'skills_gained' => ['Procure-to-Pay (P2P)', 'Material Master', 'Vendor Master', 'Inventory Management (MIGO)', 'Invoice Verification (MIRO)'],
                'thumbnail' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: SAP Materials Management & P2P Cycle',
                        'lessons' => [
                            ['title' => 'Enterprise Structure & Master Data for Procurement', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Purchasing Documents, Goods Receipt & Invoice Verification', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'SAP MM Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Full Enterprise Procurement Cycle Implementation', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'SAP PP',
                'slug' => 'sap-pp',
                'category' => 'SAP',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 21999,
                'description' => 'Production Planning: Bill of Materials (BOM), Work Centers, Routing, Material Requirement Planning (MRP), and Production Orders.',
                'full_description' => "SAP PP covers manufacturing workflows in SAP S/4HANA: demand management, discrete manufacturing, capacity planning, and shop floor control.",
                'prerequisites' => ['SAP Enterprise Fundamentals', 'Basic manufacturing / supply chain awareness'],
                'learning_objectives' => [
                    'Create and maintain Bills of Material (BOM), Work Centers, and Routings',
                    'Run Material Requirements Planning (MRP) and evaluate stock/requirements lists',
                    'Execute production orders from release to confirmation and goods receipt',
                ],
                'skills_gained' => ['Bill of Materials (BOM)', 'Work Centers & Routing', 'MRP Execution', 'Production Orders', 'Capacity Planning'],
                'thumbnail' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Production Planning & Manufacturing Execution',
                        'lessons' => [
                            ['title' => 'BOM, Work Centers & Routing Configuration', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'MRP Runs, Planned Orders & Production Order Execution', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'SAP PP Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Complete Manufacturing Plant Planning Setup', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'SAP BTP/CPI',
                'slug' => 'sap-btp-cpi',
                'category' => 'SAP',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 22999,
                'description' => 'SAP Business Technology Platform, Cloud Integration (CPI), Integration Flows (iFlows), OData/SOAP/REST adapters, and Groovy Scripting.',
                'full_description' => "SAP BTP/CPI trains integration consultants to connect SAP S/4HANA with cloud and third-party systems using Integration Flows, message transformations, and security keys.",
                'prerequisites' => ['SAP Enterprise Fundamentals', 'Basic API / XML / JSON knowledge'],
                'learning_objectives' => [
                    'Design and deploy Integration Flows (iFlows) in SAP Cloud Integration',
                    'Configure HTTPS, SFTP, OData, SOAP, and IDoc communication adapters',
                    'Write custom Groovy scripts for complex payload transformation and routing',
                ],
                'skills_gained' => ['SAP BTP Cloud Integration', 'iFlow Design', 'Groovy Scripting', 'OData / REST / SOAP', 'Security Artifacts'],
                'thumbnail' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: SAP Cloud Integration & iFlow Architecture',
                        'lessons' => [
                            ['title' => 'BTP Integration Suite & Cloud Integration Architecture', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'iFlow Design, Message Routing & Groovy Transformations', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'SAP BTP/CPI Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: End-to-End S/4HANA to Third-Party Cloud Integration Flow', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],

            // Other Categories Intermediate
            [
                'title' => 'Digital Marketing',
                'slug' => 'digital-marketing',
                'category' => 'Marketing & Business',
                'difficulty' => 'Intermediate',
                'duration' => '8 Weeks',
                'instructor' => 'Kavita Menon',
                'price' => 17999,
                'description' => 'SEO strategies, Google Ads search & display campaigns, Meta Ads Manager, Conversion Rate Optimization, and Google Analytics 4.',
                'full_description' => "Digital Marketing covers advanced performance marketing: tracking ROI, running A/B conversion tests, configuring GA4 event tracking, and building high-converting ad funnels.",
                'prerequisites' => ['Digital Marketing Fundamentals'],
                'learning_objectives' => [
                    'Execute technical and on-page SEO audits and link-building campaigns',
                    'Manage paid search and paid social campaigns on Google Ads and Meta',
                    'Configure Google Analytics 4 custom conversions and analyze attribution models',
                ],
                'skills_gained' => ['Google Ads (PPC)', 'Meta Ads Manager', 'SEO Audits', 'Google Analytics 4', 'Conversion Rate Optimization'],
                'thumbnail' => 'https://images.unsplash.com/photo-1533750349088-cd871a92f312?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Performance Marketing & Analytics',
                        'lessons' => [
                            ['title' => 'Technical SEO Auditing & Keyword Architecture', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'PPC Campaign Management & Google Analytics 4', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Digital Marketing Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Comprehensive Paid Acquisition & SEO Campaign', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Medical Coding',
                'slug' => 'medical-coding',
                'category' => 'Healthcare & Life Sciences',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Dr. R. K. Sharma',
                'price' => 19999,
                'description' => 'ICD-10-CM diagnostic coding, CPT procedure coding, HCPCS Level II, medical billing guidelines, and clinical chart auditing.',
                'full_description' => "Medical Coding provides professional training for Certified Professional Coder (CPC) preparation: abstracting clinical charts, assigning valid diagnosis/procedure codes, and handling claim denials.",
                'prerequisites' => ['Medical Terminology & Healthcare Fundamentals'],
                'learning_objectives' => [
                    'Assign accurate ICD-10-CM diagnosis codes following official coding guidelines',
                    'Code surgical and evaluation procedures using CPT and HCPCS Level II',
                    'Audit clinical medical charts to ensure reimbursement compliance',
                ],
                'skills_gained' => ['ICD-10-CM Coding', 'CPT Modifiers', 'HCPCS Level II', 'Medical Billing', 'Chart Auditing'],
                'thumbnail' => 'https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: ICD-10 & CPT Procedural Coding',
                        'lessons' => [
                            ['title' => 'ICD-10-CM Conventions, Chapters & Guidelines', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'CPT Coding Guidelines, Modifiers & E/M Auditing', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Medical Coding Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Comprehensive Clinical Medical Chart Coding Audit', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Software Testing With AI',
                'slug' => 'software-testing-with-ai',
                'category' => 'Quality Assurance & Testing',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 19999,
                'description' => 'AI-driven test automation, Selenium WebDriver with Java/Python, automated test generation, visual regression testing, and CI/CD integration.',
                'full_description' => "Software Testing With AI teaches QA engineers to leverage AI test agents, Selenium automation, and self-healing test frameworks to supercharge software testing throughput.",
                'prerequisites' => ['Software Testing Fundamentals', 'Basic programming knowledge'],
                'learning_objectives' => [
                    'Build test automation suites using Selenium WebDriver and TestNG/PyTest',
                    'Leverage AI tools for automated test case generation and self-healing locators',
                    'Execute cross-browser automated tests in continuous integration pipelines',
                ],
                'skills_gained' => ['Selenium WebDriver', 'AI Test Generation', 'TestNG/PyTest', 'Automated Regression', 'CI/CD Test Pipelines'],
                'thumbnail' => 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Test Automation & AI Quality Tools',
                        'lessons' => [
                            ['title' => 'Selenium WebDriver Frameworks & Page Object Model', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'AI Test Automation & Self-Healing Locators', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Software Testing Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Complete Automated E-Commerce Test Suite', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Automation Testing',
                'slug' => 'automation-testing',
                'category' => 'Quality Assurance & Testing',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 19999,
                'description' => 'Modern browser automation with Cypress and Playwright: Page Object Models, API mocking, parallel test execution, and CI/CD reporting.',
                'full_description' => "Automation Testing focuses on next-generation web testing using Playwright and Cypress: async handling, network interception, visual testing, and headless execution.",
                'prerequisites' => ['Software Testing Fundamentals', 'JavaScript basics'],
                'learning_objectives' => [
                    'Write end-to-end tests using Playwright and Cypress',
                    'Implement Page Object Models (POM) for clean, maintainable test code',
                    'Mock backend network requests and test edge cases deterministically',
                ],
                'skills_gained' => ['Playwright', 'Cypress', 'Page Object Model', 'Network Mocking', 'E2E Testing'],
                'thumbnail' => 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Next-Gen E2E Web Automation',
                        'lessons' => [
                            ['title' => 'Playwright & Cypress Architecture and Setup', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Page Object Model & Parallel Test Runner Execution', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Automation Testing Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Production E2E Automation Framework in Playwright', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'API Testing',
                'slug' => 'api-testing',
                'category' => 'Quality Assurance & Testing',
                'difficulty' => 'Intermediate',
                'duration' => '8 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 16999,
                'description' => 'REST & GraphQL API testing: Postman automated assertions, Newman CLI runner, REST Assured, and API contract validation.',
                'full_description' => "API Testing covers automated validation of backend services: HTTP status codes, JSON schema validation, authentication flows, and CI/CD test automation.",
                'prerequisites' => ['Software Testing Fundamentals', 'Basic HTTP protocol knowledge'],
                'learning_objectives' => [
                    'Build automated test suites in Postman with JavaScript assertions',
                    'Execute Postman collections via Newman CLI in automated build pipelines',
                    'Validate JSON and GraphQL schemas against API specifications',
                ],
                'skills_gained' => ['Postman Automation', 'Newman CLI', 'REST Assured', 'JSON Schema Validation', 'Contract Testing'],
                'thumbnail' => 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: REST API Automation & Validation',
                        'lessons' => [
                            ['title' => 'Postman Test Scripting, Variables & Environments', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Automated Execution with Newman & Schema Verification', 'type' => 'text', 'duration' => '30 min'],
                            ['title' => 'API Testing Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Comprehensive API Test Suite with CI Integration', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Mobile Applications',
                'slug' => 'mobile-applications',
                'category' => 'Mobile Engineering',
                'difficulty' => 'Intermediate',
                'duration' => '12 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 22999,
                'description' => 'Cross-platform mobile development using React Native / Flutter: state management, native device APIs, push notifications, and App Store releases.',
                'full_description' => "Mobile Applications teaches cross-platform mobile development: reusable components, mobile state management, offline persistence, and publishing to iOS and Android stores.",
                'prerequisites' => ['Mobile App Development Fundamentals', 'JavaScript / React basics'],
                'learning_objectives' => [
                    'Build cross-platform mobile apps with React Native or Flutter',
                    'Access camera, geolocation, and local storage on iOS & Android devices',
                    'Implement push notifications and offline data synchronization',
                ],
                'skills_gained' => ['React Native / Flutter', 'Mobile State Management', 'Native Device APIs', 'Push Notifications', 'App Store Publishing'],
                'thumbnail' => 'https://images.unsplash.com/photo-1512941937669-90a1b58e7e9c?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Cross-Platform Mobile Architecture',
                        'lessons' => [
                            ['title' => 'Mobile Component Composition & Native Navigation', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Device APIs: Camera, Location, and Secure Storage', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Mobile Applications Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Production Cross-Platform Mobile Social App', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Android Development',
                'slug' => 'android-development',
                'category' => 'Mobile Engineering',
                'difficulty' => 'Intermediate',
                'duration' => '12 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 22999,
                'description' => 'Native Android development with Kotlin: Jetpack Compose UI, Coroutines, Room database, Retrofit networking, and Clean Architecture.',
                'full_description' => "Android Development covers modern native Android engineering using Kotlin: declarative Jetpack Compose interfaces, asynchronous Coroutines, Room persistence, and MVVM patterns.",
                'prerequisites' => ['Mobile App Development Fundamentals', 'Basic OOP programming knowledge'],
                'learning_objectives' => [
                    'Build declarative modern Android user interfaces with Jetpack Compose',
                    'Manage asynchronous background tasks with Kotlin Coroutines and Flows',
                    'Implement offline caching with Room SQLite database and Retrofit REST client',
                ],
                'skills_gained' => ['Kotlin', 'Jetpack Compose', 'Coroutines & Flow', 'Room Database', 'MVVM Clean Architecture'],
                'thumbnail' => 'https://images.unsplash.com/photo-1512941937669-90a1b58e7e9c?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Modern Native Android Engineering',
                        'lessons' => [
                            ['title' => 'Kotlin Fundamentals & Jetpack Compose Declarative UI', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Coroutines, StateFlow, Room Database & Retrofit', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Android Development Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Native Android News & Productivity Application', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'UI/UX Design',
                'slug' => 'ui-ux-design',
                'category' => 'Design & Creative',
                'difficulty' => 'Intermediate',
                'duration' => '10 Weeks',
                'instructor' => 'Elena Rostova',
                'price' => 19999,
                'description' => 'Design systems in Figma, interactive micro-animations, user testing methodologies, responsive web/mobile UI, and developer handoff.',
                'full_description' => "UI/UX Design covers end-to-end digital product design: building reusable design systems, components, auto-layout, interactive prototypes, and usability testing.",
                'prerequisites' => ['UI/UX Design Fundamentals'],
                'learning_objectives' => [
                    'Architect scalable design systems with tokens, variants, and auto-layout in Figma',
                    'Build high-fidelity interactive prototypes with smart animations',
                    'Conduct moderated usability testing sessions and iterate on user feedback',
                ],
                'skills_gained' => ['Figma Design Systems', 'Interactive Prototyping', 'Auto-Layout & Variants', 'Usability Testing', 'Developer Handoff'],
                'thumbnail' => 'https://images.unsplash.com/photo-1581291518857-4e27b48ff24e?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Scalable Design Systems & Prototyping',
                        'lessons' => [
                            ['title' => 'Figma Design System Architecture & Design Tokens', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Complex Prototyping, Micro-Interactions & Usability Testing', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'UI/UX Design Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Complete Multi-Platform Product Design System', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function getAdvancedCourses(): array
    {
        return [
            // Cyber Security Advanced
            [
                'title' => 'Advanced Ethical Hacking',
                'slug' => 'advanced-ethical-hacking',
                'category' => 'Cyber Security',
                'difficulty' => 'Advanced',
                'duration' => '14 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 29999,
                'description' => 'Advanced exploit development, custom payload evasion, Active Directory exploitation, Kerberoasting, and post-exploitation workflows.',
                'full_description' => "Advanced Ethical Hacking focuses on offensive security operations in complex enterprise environments: bypass techniques, memory inspection, and Active Directory persistence in authorized lab networks.",
                'prerequisites' => ['Ethical Hacking (Intermediate) or equivalent penetration testing experience'],
                'learning_objectives' => [
                    'Perform Active Directory attacks (Kerberoasting, AS-REP roasting, DCSync)',
                    'Understand EDR evasion concepts and custom payload obfuscation techniques',
                    'Execute advanced network pivoting across isolated enterprise subnets',
                ],
                'skills_gained' => ['Active Directory Pentesting', 'BloodHound Analysis', 'Kerberos Exploitation', 'Network Pivoting', 'Post-Exploitation'],
                'thumbnail' => 'https://images.unsplash.com/photo-1526374965328-7f61d4dc18c5?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Enterprise Active Directory Exploitation',
                        'lessons' => [
                            ['title' => 'Active Directory Architecture & Kerberos Ticket Authentication', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'BloodHound Graphing, Kerberoasting & Golden Ticket Attacks', 'type' => 'text', 'duration' => '40 min'],
                            ['title' => 'Pivoting & Port Forwarding Through Multi-Homed Lab Hosts', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Advanced Ethical Hacking Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Compromise & Audit an Enterprise AD Lab Forest', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Advanced Penetration Testing',
                'slug' => 'advanced-penetration-testing',
                'category' => 'Cyber Security',
                'difficulty' => 'Advanced',
                'duration' => '14 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 29999,
                'description' => 'Internal enterprise penetration testing: network segmentation bypass, lateral movement, credential extraction, and executive remediation roadmaps.',
                'full_description' => "Advanced Penetration Testing covers multi-vector internal audits against complex infrastructure: wireless, internal network, virtualization, and Active Directory.",
                'prerequisites' => ['Ethical Hacking', 'Advanced networking knowledge'],
                'learning_objectives' => [
                    'Execute full internal penetration testing following PTES methodologies',
                    'Demonstrate lateral movement techniques without triggering basic alerts',
                    'Produce comprehensive technical and executive vulnerability reports',
                ],
                'skills_gained' => ['PTES Methodology', 'Lateral Movement', 'Credential Dumping', 'Network Segmentation Audits', 'Executive Reporting'],
                'thumbnail' => 'https://images.unsplash.com/photo-1526374965328-7f61d4dc18c5?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Advanced Internal Penetration Auditing',
                        'lessons' => [
                            ['title' => 'PTES Standard & Enterprise Network Scoping', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'Lateral Movement & Pass-the-Hash / Pass-the-Ticket Attacks', 'type' => 'text', 'duration' => '40 min'],
                            ['title' => 'Advanced Penetration Testing Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Full Enterprise Infrastructure Penetration Audit', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Red Teaming',
                'slug' => 'red-teaming',
                'category' => 'Cyber Security',
                'difficulty' => 'Advanced',
                'duration' => '14 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 34999,
                'description' => 'Adversary emulation, MITRE ATT&CK mapping, Command & Control (C2) infrastructure design, evasion techniques, and operational security.',
                'full_description' => "Red Teaming simulates real-world advanced persistent threat (APT) behavior to test an organization’s detection and response capabilities in authorized lab simulations.",
                'prerequisites' => ['Advanced Penetration Testing or senior security engineering experience'],
                'learning_objectives' => [
                    'Plan and execute adversary simulation campaigns mapped to MITRE ATT&CK',
                    'Deploy secure Command and Control (C2) infrastructure with redirectors',
                    'Evaluate Blue Team detection efficacy and facilitate Purple Team debriefs',
                ],
                'skills_gained' => ['MITRE ATT&CK', 'C2 Infrastructure', 'Adversary Emulation', 'Purple Teaming', 'OPSEC Discipline'],
                'thumbnail' => 'https://images.unsplash.com/photo-1526374965328-7f61d4dc18c5?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Adversary Simulation & C2 Operations',
                        'lessons' => [
                            ['title' => 'Red Team Operational Planning & MITRE ATT&CK Mapping', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'C2 Infrastructure Architecture & Egress Traffic Profiling', 'type' => 'text', 'duration' => '40 min'],
                            ['title' => 'Red Teaming Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Multi-Stage Adversary Simulation Operation', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Web Application Security Engineering',
                'slug' => 'web-application-security-engineering',
                'category' => 'Cyber Security',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 27999,
                'description' => 'DevSecOps automation, SAST/DAST integration, threat modeling for microservices, zero-trust web architectures, and advanced cryptography.',
                'full_description' => "Web Application Security Engineering trains software security engineers to design self-defending web applications and embed automated security gates into CI/CD pipelines.",
                'prerequisites' => ['Web Application Security', 'Full Stack Development experience'],
                'learning_objectives' => [
                    'Embed automated SAST and DAST scanners into GitHub Actions pipelines',
                    'Conduct architectural threat modeling for microservices and cloud APIs',
                    'Implement defense against deserialization and server-side request forgery (SSRF)',
                ],
                'skills_gained' => ['DevSecOps', 'SAST / DAST Automation', 'SSRF Defense', 'Cryptographic Implementation', 'AppSec Architecture'],
                'thumbnail' => 'https://images.unsplash.com/photo-1555949963-ff9fe0c870eb?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: DevSecOps & Advanced AppSec Architecture',
                        'lessons' => [
                            ['title' => 'Automating Security Scanners (SAST/DAST/SCA) in CI/CD', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Advanced Web Vulnerabilities: SSRF, Deserialization & Race Conditions', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Web AppSec Engineering Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Automated DevSecOps Pipeline & Secure Architecture Audit', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'API Security',
                'slug' => 'api-security',
                'category' => 'Cyber Security',
                'difficulty' => 'Advanced',
                'duration' => '10 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 24999,
                'description' => 'OWASP API Security Top 10, GraphQL security audits, OAuth2/OIDC vulnerability exploitation and hardening, and API Gateway security.',
                'full_description' => "API Security covers dedicated security assessments and architectural hardening of REST, GraphQL, and gRPC endpoints in modern distributed systems.",
                'prerequisites' => ['Web Application Security', 'API development knowledge'],
                'learning_objectives' => [
                    'Exploit and prevent OWASP API Security Top 10 vulnerabilities (BOLA, BFLA, Mass Assignment)',
                    'Audit OAuth 2.0 and OpenID Connect token verification workflows',
                    'Harden GraphQL endpoints against query depth and circular introspection attacks',
                ],
                'skills_gained' => ['OWASP API Top 10', 'BOLA / IDOR Exploitation', 'OAuth 2.0 Hardening', 'GraphQL Security', 'API Gateways'],
                'thumbnail' => 'https://images.unsplash.com/photo-1526374965328-7f61d4dc18c5?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: API Vulnerability Auditing & Hardening',
                        'lessons' => [
                            ['title' => 'OWASP API Top 10: Broken Object Level Authorization (BOLA)', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'OAuth 2.0 Flow Flaws, JWT Validation & GraphQL Security', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'API Security Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Complete Security Assessment of an Enterprise API Suite', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Security Operations & SOC',
                'slug' => 'security-operations-soc',
                'category' => 'Cyber Security',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 26999,
                'description' => 'Security Operations Center engineering: Splunk, Elastic SIEM, threat hunting, SOAR playbook automation, and incident escalation.',
                'full_description' => "Security Operations & SOC prepares senior analysts and SOC engineers to configure detection analytics, hunt stealthy threats, and automate response workflows with SOAR.",
                'prerequisites' => ['Cyber Security (Intermediate)'],
                'learning_objectives' => [
                    'Write complex detection queries in Splunk (SPL) and Elastic Security (KQL/EQL)',
                    'Conduct hypothesis-driven threat hunting against adversary tactics',
                    'Build automated incident triage and containment playbooks with SOAR',
                ],
                'skills_gained' => ['SIEM Engineering (Splunk/Elastic)', 'Threat Hunting', 'SOAR Automation', 'Incident Triage', 'Log Forensics'],
                'thumbnail' => 'https://images.unsplash.com/photo-1563986768609-322da13575f3?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Detection Engineering & Threat Hunting',
                        'lessons' => [
                            ['title' => 'SIEM Detection Rules & Correlation Query Design', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Threat Hunting Methodologies & SOAR Playbook Design', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'SOC Engineering Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Automated SOC Incident Response & Threat Hunt Lab', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Digital Forensics',
                'slug' => 'digital-forensics',
                'category' => 'Cyber Security',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 26999,
                'description' => 'Memory forensics with Volatility, disk imaging with Autopsy, network traffic forensics with Zeek, evidence preservation, and chain of custody.',
                'full_description' => "Digital Forensics covers forensic acquisition and analysis of volatile memory, file system artifacts (MFT, Registry, Prefetch), and packet captures for incident investigations.",
                'prerequisites' => ['Cyber Security (Intermediate)', 'Linux/Windows internals awareness'],
                'learning_objectives' => [
                    'Acquire bit-stream disk images and preserve cryptographic chain of custody',
                    'Analyze volatile RAM dumps using Volatility 3 to extract injected code and sockets',
                    'Reconstruct attacker activity through Windows Event Logs and filesystem artifacts',
                ],
                'skills_gained' => ['Volatility Memory Analysis', 'Autopsy Disk Forensics', 'Chain of Custody', 'Windows Artifacts', 'Timeline Reconstruction'],
                'thumbnail' => 'https://images.unsplash.com/photo-1526374965328-7f61d4dc18c5?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Forensic Acquisition & Analysis',
                        'lessons' => [
                            ['title' => 'Evidence Acquisition, Hashing & Memory Extraction', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Memory Analysis with Volatility 3 & Artifact Parsing', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Digital Forensics Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Forensic Investigation & Expert Incident Report', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Cloud Security',
                'slug' => 'cloud-security',
                'category' => 'Cyber Security',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 27999,
                'description' => 'Multi-cloud security architecture: AWS/Azure IAM least-privilege, CSPM/CWPP tooling, Kubernetes security, and Policy as Code with Terraform.',
                'full_description' => "Cloud Security teaches cloud security architects how to protect multi-cloud environments, detect misconfigurations, enforce IAM least-privilege, and secure container runtimes.",
                'prerequisites' => ['AWS Cloud Engineering or DevOps Engineering', 'Cyber Security basics'],
                'learning_objectives' => [
                    'Enforce granular IAM policies and boundary conditions on AWS and Azure',
                    'Implement Cloud Security Posture Management (CSPM) and GuardDuty alerts',
                    'Secure Kubernetes clusters with network policies, RBAC, and admission controllers',
                ],
                'skills_gained' => ['Cloud IAM Hardening', 'CSPM / CWPP', 'Kubernetes Security', 'Policy as Code (OPA)', 'Cloud Incident Response'],
                'thumbnail' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Multi-Cloud Security & Governance',
                        'lessons' => [
                            ['title' => 'Cloud Identity Governance & IAM Least-Privilege Design', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Kubernetes Hardening & Automated Policy as Code', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Cloud Security Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Multi-Cloud Enterprise Security Architecture Audit', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],

            // Full Stack / Software Engineering Advanced
            [
                'title' => 'Advanced Full Stack Engineering',
                'slug' => 'advanced-full-stack-engineering',
                'category' => 'FULL STACK',
                'difficulty' => 'Advanced',
                'duration' => '14 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 29999,
                'description' => 'Next.js App Router, React Server Components, TypeScript strict mode, Prisma ORM, Redis caching, and continuous deployment.',
                'full_description' => "Advanced Full Stack Engineering focuses on high-performance full-stack architectures: server actions, optimistic UI updates, Redis distributed caching, and micro-frontend design.",
                'prerequisites' => ['Full Stack Web Development (Intermediate)'],
                'learning_objectives' => [
                    'Build high-performance web applications with Next.js App Router and Server Components',
                    'Implement TypeScript strict typing across client, server, and database layers',
                    'Integrate Redis caching and asynchronous queue workers for sub-second responsiveness',
                ],
                'skills_gained' => ['Next.js 15', 'Server Actions', 'TypeScript Strict', 'Prisma ORM', 'Redis Caching', 'Edge Deployment'],
                'thumbnail' => 'https://images.unsplash.com/photo-1517694712202-14dd9538aa97?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Modern Full Stack Architecture with Next.js',
                        'lessons' => [
                            ['title' => 'Next.js App Router, Server Components & Streaming SSR', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Database Operations with Prisma & Redis Distributed Caching', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Advanced Full Stack Engineering Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Scalable Multi-Tenant Enterprise Web Platform', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Advanced React Engineering',
                'slug' => 'advanced-react-engineering',
                'category' => 'FULL STACK',
                'difficulty' => 'Advanced',
                'duration' => '10 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 22999,
                'description' => 'React 19 internals, concurrent rendering, custom hooks architecture, performance profiling, memory optimization, and micro-frontends.',
                'full_description' => "Advanced React Engineering teaches deep mastery of React runtime mechanics: fibers, concurrent rendering, state machine design with XState, and complex memoization strategies.",
                'prerequisites' => ['Full Stack Web Development or solid React experience'],
                'learning_objectives' => [
                    'Profile React applications using React DevTools and Chrome Performance Profiler',
                    'Leverage React 19 Concurrent features (useTransition, useDeferredValue, Server Actions)',
                    'Design custom hook libraries and scalable design system component primitives',
                ],
                'skills_gained' => ['React 19', 'Concurrent Mode', 'Performance Profiling', 'Custom Hooks Architecture', 'Micro-Frontends'],
                'thumbnail' => 'https://images.unsplash.com/photo-1633356122544-f134324a6cee?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: React Internals & Performance Optimization',
                        'lessons' => [
                            ['title' => 'React Fiber Architecture, Reconciliation & Concurrent Mode', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Profiling Rerenders, Memory Leaks & Custom Hook Design', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Advanced React Engineering Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: High-Performance Data Visualization Dashboard in React', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Backend Engineering with Laravel',
                'slug' => 'backend-engineering-with-laravel',
                'category' => 'FULL STACK',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 24999,
                'description' => 'Domain-Driven Design (DDD), Service-Repository architecture, Laravel Horizon queues, Redis broadcasting, API security, and high-concurrency scaling.',
                'full_description' => "Backend Engineering with Laravel covers enterprise PHP development: decoupling controllers with action classes, database query optimization, event-driven architectures, and Sanctum/Passport API security.",
                'prerequisites' => ['PHP / Laravel basics or full-stack backend experience'],
                'learning_objectives' => [
                    'Architect maintainable Laravel backends using Domain-Driven Design and Action classes',
                    'Scale asynchronous background processing with Redis and Laravel Horizon',
                    'Optimize database queries, eager loading, and indexes to eliminate N+1 performance bottlenecks',
                ],
                'skills_gained' => ['Laravel 11', 'Domain-Driven Design', 'Redis & Horizon Queues', 'Query Optimization', 'Enterprise REST APIs'],
                'thumbnail' => 'https://images.unsplash.com/photo-1517694712202-14dd9538aa97?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Enterprise Laravel Architecture & Queues',
                        'lessons' => [
                            ['title' => 'Domain-Driven Design & Modular Service Architecture in Laravel', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'High-Concurrency Queue Workers & Database Optimization', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Backend Laravel Engineering Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: High-Throughput Fintech Transaction Backend in Laravel', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Microservices Architecture',
                'slug' => 'microservices-architecture',
                'category' => 'FULL STACK',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 27999,
                'description' => 'Service decomposition, event-driven messaging with RabbitMQ/Kafka, API Gateways, the Saga pattern, distributed tracing, and resilient fault tolerance.',
                'full_description' => "Microservices Architecture teaches software architects how to break monoliths into autonomous, resilient microservices with event-driven communication and distributed consistency.",
                'prerequisites' => ['Full Stack Web Development or backend software engineering experience'],
                'learning_objectives' => [
                    'Decompose monolithic systems into bounded contexts following Domain-Driven Design',
                    'Implement asynchronous event-driven communication with Kafka and RabbitMQ',
                    'Ensure distributed data consistency using the Saga pattern and Outbox pattern',
                ],
                'skills_gained' => ['Microservices Design', 'Apache Kafka / RabbitMQ', 'Saga Pattern', 'Distributed Tracing', 'API Gateways'],
                'thumbnail' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Distributed Microservices Architecture',
                        'lessons' => [
                            ['title' => 'Bounded Contexts & Monolith-to-Microservices Migration', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Event-Driven Architectures with Kafka & Saga Pattern', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Microservices Architecture Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Event-Driven E-Commerce Microservices Platform', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'API Architecture & Engineering',
                'slug' => 'api-architecture-engineering',
                'category' => 'FULL STACK',
                'difficulty' => 'Advanced',
                'duration' => '10 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 22999,
                'description' => 'REST, GraphQL, gRPC, OpenAPI specifications, distributed rate limiting, API versioning strategies, and low-latency API Gateways.',
                'full_description' => "API Architecture & Engineering covers the complete discipline of enterprise API design: choosing between REST, GraphQL, and gRPC, establishing rate limits, telemetry, and developer ecosystems.",
                'prerequisites' => ['Full Stack Web Development'],
                'learning_objectives' => [
                    'Design contract-first APIs using OpenAPI/Swagger and AsyncAPI specifications',
                    'Build ultra-low-latency microservice RPC interfaces using Protocol Buffers and gRPC',
                    'Implement token-bucket rate limiting and caching strategies at API Gateways',
                ],
                'skills_gained' => ['gRPC & Protobuf', 'GraphQL Federation', 'OpenAPI 3.0 Spec', 'API Rate Limiting', 'Gateway Architecture'],
                'thumbnail' => 'https://images.unsplash.com/photo-1517694712202-14dd9538aa97?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Enterprise API Design & Protocols',
                        'lessons' => [
                            ['title' => 'Contract-First API Design & OpenAPI Specifications', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'gRPC vs GraphQL vs REST: Performance & Federation', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'API Architecture Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise Multi-Protocol API Gateway Suite', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Software Architecture & System Design',
                'slug' => 'software-architecture-system-design',
                'category' => 'FULL STACK',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 29999,
                'description' => 'Large-scale distributed system design: load balancing, database sharding, CAP theorem, caching tiers, consensus algorithms, and fault tolerance.',
                'full_description' => "Software Architecture & System Design prepares senior engineers to design massive scale systems capable of handling millions of requests per second with high availability.",
                'prerequisites' => ['Full Stack Web Development', 'Database & networking fundamentals'],
                'learning_objectives' => [
                    'Design scalable architectures handling millions of daily active users',
                    'Implement database partitioning, sharding, and replication strategies',
                    'Evaluate tradeoffs between consistency, availability, and partition tolerance (CAP Theorem)',
                ],
                'skills_gained' => ['High-Level System Design', 'Database Sharding', 'CAP Theorem', 'Distributed Caching', 'Fault Tolerance'],
                'thumbnail' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Scalability & High-Level System Design',
                        'lessons' => [
                            ['title' => 'System Design Fundamentals: Scalability, Latency & Throughput', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'Database Sharding, Replication & Distributed Caching', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'System Design Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Design a Global Scalable Video Streaming Architecture', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],

            // AI Advanced
            [
                'title' => 'Advanced Machine Learning',
                'slug' => 'advanced-machine-learning',
                'category' => 'ARTIFICIAL INTELLIGENCE',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Dr. Aris Thorne',
                'price' => 27999,
                'description' => 'Ensemble gradient boosting (XGBoost, LightGBM, CatBoost), Bayesian hyperparameter optimization with Optuna, explainable AI (SHAP/LIME), and MLflow.',
                'full_description' => "Advanced Machine Learning covers production tabular modeling, complex ensemble stacking, feature store integration, and model interpretability.",
                'prerequisites' => ['Machine Learning (Intermediate)', 'Solid statistics & Python skills'],
                'learning_objectives' => [
                    'Train state-of-the-art Gradient Boosting models with XGBoost, LightGBM, and CatBoost',
                    'Implement automated Bayesian hyperparameter tuning with Optuna',
                    'Interpret complex black-box models using SHAP values and LIME explanations',
                ],
                'skills_gained' => ['XGBoost / LightGBM', 'Optuna Optimization', 'Explainable AI (SHAP)', 'Ensemble Stacking', 'MLflow Tracking'],
                'thumbnail' => 'https://images.unsplash.com/photo-1555949963-aa79dcee981c?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Gradient Boosting & Model Interpretability',
                        'lessons' => [
                            ['title' => 'XGBoost, LightGBM & CatBoost Architecture', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Bayesian Optimization with Optuna & SHAP Explanations', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Advanced ML Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: High-Stakes Financial Risk Modeling with SHAP Explainability', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Deep Learning',
                'slug' => 'deep-learning',
                'category' => 'ARTIFICIAL INTELLIGENCE',
                'difficulty' => 'Advanced',
                'duration' => '14 Weeks',
                'instructor' => 'Dr. Aris Thorne',
                'price' => 29999,
                'description' => 'Convolutional Neural Networks (CNNs), Recurrent Networks (LSTMs), Transformer architectures from scratch in PyTorch, and distributed GPU training.',
                'full_description' => "Deep Learning provides mathematical and computational mastery of deep neural networks: backpropagation, self-attention mechanisms, PyTorch distributed data parallel (DDP), and model quantization.",
                'prerequisites' => ['Machine Learning (Intermediate)', 'Linear algebra & calculus'],
                'learning_objectives' => [
                    'Implement Custom PyTorch modules, loss functions, and training loops',
                    'Build Computer Vision models with ResNet, Vision Transformers (ViT), and YOLO',
                    'Implement the Transformer self-attention architecture from scratch in PyTorch',
                ],
                'skills_gained' => ['PyTorch Deep Learning', 'Transformer Architecture', 'Computer Vision (CNNs)', 'Attention Mechanisms', 'GPU Training (DDP)'],
                'thumbnail' => 'https://images.unsplash.com/photo-1620712943543-bcc4688e7485?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Deep Neural Networks & Transformer Architectures',
                        'lessons' => [
                            ['title' => 'PyTorch Internals: Autograd, Tensors & Custom Modules', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'Self-Attention & Multi-Head Transformers from Scratch', 'type' => 'text', 'duration' => '40 min'],
                            ['title' => 'Deep Learning Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Custom Transformer Language Model in PyTorch', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Generative AI Engineering',
                'slug' => 'generative-ai-engineering',
                'category' => 'ARTIFICIAL INTELLIGENCE',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Dr. Aris Thorne',
                'price' => 29999,
                'description' => 'Fine-tuning open-source LLMs (Llama, Mistral) with LoRA/QLoRA, synthetic data generation, advanced RAG evaluations (RAGAS), and vLLM serving.',
                'full_description' => "Generative AI Engineering bridges model research and production systems: parameter-efficient fine-tuning (PEFT), quantization (GGUF, AWQ), low-latency vLLM inference, and evaluation frameworks.",
                'prerequisites' => ['Generative AI (Intermediate)', 'Python / PyTorch basics'],
                'learning_objectives' => [
                    'Fine-tune open-source LLMs using LoRA and QLoRA with HuggingFace TRL',
                    'Quantize and deploy high-throughput models with vLLM and TensorRT-LLM',
                    'Establish automated RAG evaluation benchmarks with RAGAS and TruLens',
                ],
                'skills_gained' => ['LLM Fine-Tuning (LoRA/QLoRA)', 'vLLM Model Serving', 'RAGAS Evaluation', 'Synthetic Datasets', 'Model Quantization'],
                'thumbnail' => 'https://images.unsplash.com/photo-1677442136019-21780efad99a?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Fine-Tuning & High-Throughput Inference',
                        'lessons' => [
                            ['title' => 'PEFT Fine-Tuning: LoRA, QLoRA & Dataset Preparation', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'High-Throughput Model Serving with vLLM & Quantization', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'GenAI Engineering Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Domain-Specific Fine-Tuned LLM & Production Serving', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'LLM Application Engineering',
                'slug' => 'llm-application-engineering',
                'category' => 'ARTIFICIAL INTELLIGENCE',
                'difficulty' => 'Advanced',
                'duration' => '10 Weeks',
                'instructor' => 'Dr. Aris Thorne',
                'price' => 26999,
                'description' => 'Production LLM application infrastructure: semantic caching, prompt firewalls, structured outputs with Pydantic, rate-limiting, and cost optimization.',
                'full_description' => "LLM Application Engineering teaches software engineers to build enterprise-grade applications powered by foundation models: managing latency, fallback providers, and telemetry.",
                'prerequisites' => ['Generative AI or Python With AI'],
                'learning_objectives' => [
                    'Implement semantic caching with GPTCache and Redis to reduce LLM costs by 60%',
                    'Enforce structured outputs and JSON schema guarantees using instructor and Pydantic',
                    'Deploy LLM guardrails (NeMo Guardrails, Llama Guard) to prevent prompt injection',
                ],
                'skills_gained' => ['Semantic Caching', 'LLM Guardrails', 'Pydantic Structured Output', 'Cost Optimization', 'Model Observability'],
                'thumbnail' => 'https://images.unsplash.com/photo-1677442136019-21780efad99a?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Enterprise LLM Infrastructure & Guardrails',
                        'lessons' => [
                            ['title' => 'Semantic Caching, Rate Limiting & Multi-Provider Fallbacks', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Prompt Injection Defense & NeMo Guardrails Implementation', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'LLM App Engineering Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise Guardrailed Multi-Provider LLM Gateway', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'AI Agents & Agentic AI',
                'slug' => 'ai-agents-agentic-ai',
                'category' => 'ARTIFICIAL INTELLIGENCE',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Dr. Aris Thorne',
                'price' => 29999,
                'description' => 'Autonomous multi-agent architectures: LangGraph state machines, CrewAI, AutoGen, tool calling, long-term memory, and self-reflection loops.',
                'full_description' => "AI Agents & Agentic AI focuses on building autonomous, goal-directed AI systems: designing state graphs with LangGraph, coordinating multi-agent teams with CrewAI, and executing tool actions.",
                'prerequisites' => ['Python With AI or Generative AI'],
                'learning_objectives' => [
                    'Architect cyclical state graph workflows with LangGraph and checkpointing',
                    'Orchestrate role-playing multi-agent systems with CrewAI and AutoGen',
                    'Integrate external tools, web search, database querying, and code execution sandboxes',
                ],
                'skills_gained' => ['LangGraph', 'CrewAI Multi-Agents', 'Tool Calling / Function Calling', 'Agentic Memory', 'Self-Reflection Loops'],
                'thumbnail' => 'https://images.unsplash.com/photo-1677442136019-21780efad99a?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Multi-Agent Orchestration & State Graphs',
                        'lessons' => [
                            ['title' => 'LangGraph Cyclical State Machines & Checkpointing', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'Multi-Agent Collaboration with CrewAI & Sandboxed Tool Calling', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Agentic AI Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Autonomous Multi-Agent Market Research Team', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'MLOps',
                'slug' => 'mlops',
                'category' => 'ARTIFICIAL INTELLIGENCE',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 27999,
                'description' => 'Machine Learning Operations: Continuous Training (CT) pipelines, Feast Feature Store, MLflow Model Registry, model drift monitoring with Evidently, and KServe.',
                'full_description' => "MLOps trains engineers to take experimental ML models into automated, reproducible production: automated retraining, feature stores, drift detection, and canary deployments.",
                'prerequisites' => ['Machine Learning (Intermediate)', 'DevOps Engineering basics'],
                'learning_objectives' => [
                    'Build automated Continuous Training (CT) pipelines with Kubeflow and MLflow',
                    'Implement centralized feature management using Feast Feature Store',
                    'Monitor production models for data drift and concept drift with Evidently AI',
                ],
                'skills_gained' => ['MLflow Model Registry', 'Feast Feature Store', 'Kubeflow Pipelines', 'Drift Monitoring (Evidently)', 'KServe Deployment'],
                'thumbnail' => 'https://images.unsplash.com/photo-1555949963-aa79dcee981c?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Continuous Training & Production MLOps',
                        'lessons' => [
                            ['title' => 'MLflow Tracking, Model Registry & Versioning Workflows', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Feature Stores (Feast) & Data Drift Detection with Evidently', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'MLOps Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Automated Production Continuous Training Pipeline', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'AI System Design',
                'slug' => 'ai-system-design',
                'category' => 'ARTIFICIAL INTELLIGENCE',
                'difficulty' => 'Advanced',
                'duration' => '10 Weeks',
                'instructor' => 'Dr. Aris Thorne',
                'price' => 29999,
                'description' => 'Designing large-scale AI architectures: vector indexing at billion scale, recommendation system pipelines, ranking algorithms, and distributed GPU inference clusters.',
                'full_description' => "AI System Design covers architectural trade-offs when scaling AI systems to hundreds of millions of users: multi-stage retrieval/ranking, vector sharding, and latency optimization.",
                'prerequisites' => ['Machine Learning (Intermediate)', 'Software Architecture basics'],
                'learning_objectives' => [
                    'Design multi-stage recommendation architectures (Candidate Generation, Scoring, Reranking)',
                    'Architect billion-scale vector search systems with HNSW indexing and sharding',
                    'Optimize GPU inference clusters for low-latency real-time scoring',
                ],
                'skills_gained' => ['Recommendation Systems', 'Billion-Scale Vector Search', 'Two-Tower Models', 'GPU Cluster Sizing', 'Real-Time AI Inference'],
                'thumbnail' => 'https://images.unsplash.com/photo-1677442136019-21780efad99a?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Large-Scale AI System Architectures',
                        'lessons' => [
                            ['title' => 'Recommendation Engines & Multi-Stage Ranking Architectures', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'Billion-Scale Vector Indexing & Distributed Inference Design', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'AI System Design Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: High-Scale AI Personalized News & Video Feed Architecture', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],

            // Data Advanced
            [
                'title' => 'Advanced Data Engineering',
                'slug' => 'advanced-data-engineering',
                'category' => 'DATA ENGINEERING',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 27999,
                'description' => 'Delta Lake and Lakehouse architecture, dbt data modeling, distributed PySpark optimization, and Apache Kafka event streaming.',
                'full_description' => "Advanced Data Engineering teaches production lakehouse patterns: ACID transactions on object storage with Delta Lake, modular transformations with dbt, and Kafka stream processing.",
                'prerequisites' => ['Data Engineering (Intermediate)'],
                'learning_objectives' => [
                    'Build scalable Data Lakehouses with Delta Lake and Apache Iceberg',
                    'Model and test enterprise analytical datasets using dbt Core and SQL',
                    'Stream and process event data in real time with Apache Kafka and Spark Streaming',
                ],
                'skills_gained' => ['Delta Lake Lakehouse', 'dbt (Data Build Tool)', 'Spark Performance Tuning', 'Kafka Event Streaming', 'Data Governance'],
                'thumbnail' => 'https://images.unsplash.com/photo-1558494949-ef010cbdcc31?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Lakehouse Architecture & dbt Modeling',
                        'lessons' => [
                            ['title' => 'Delta Lake: ACID Transactions & Medallion Architecture (Bronze/Silver/Gold)', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'Data Modeling & Automated Testing with dbt Core', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Advanced Data Engineering Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Production Enterprise Lakehouse & dbt Pipeline', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Big Data Engineering',
                'slug' => 'big-data-engineering',
                'category' => 'DATA ENGINEERING',
                'difficulty' => 'Advanced',
                'duration' => '14 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 29999,
                'description' => 'Petabyte-scale distributed computing: Spark cluster tuning, Apache Hadoop HDFS, Hive, Iceberg table formats, and distributed query engines (Trino).',
                'full_description' => "Big Data Engineering covers distributed cluster computing, partitioning strategies, memory management, and processing petabytes of data reliably.",
                'prerequisites' => ['Data Engineering (Intermediate)'],
                'learning_objectives' => [
                    'Tune Spark memory management (shuffle partitions, broadcast joins, data skew handling)',
                    'Deploy modern open table formats (Apache Iceberg, Apache Hudi) on object storage',
                    'Execute federated queries across multiple data sources using Trino/Presto',
                ],
                'skills_gained' => ['Apache Spark Tuning', 'Apache Iceberg', 'Trino / Presto', 'Distributed Shuffling', 'Big Data Architecture'],
                'thumbnail' => 'https://images.unsplash.com/photo-1558494949-ef010cbdcc31?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Distributed Computing & Cluster Optimization',
                        'lessons' => [
                            ['title' => 'Spark Internals: Memory Management, Shuffles & Skew Mitigation', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'Open Table Formats: Apache Iceberg & Federated Trino Queries', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Big Data Engineering Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Petabyte-Scale Analytics Cluster & Query Engine', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Real-Time Data Engineering',
                'slug' => 'real-time-data-engineering',
                'category' => 'DATA ENGINEERING',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 27999,
                'description' => 'Event-driven stream processing: Apache Kafka cluster administration, Kafka Streams, Apache Flink stateful streaming, and Schema Registry.',
                'full_description' => "Real-Time Data Engineering teaches sub-second event stream processing: event time windowing, exactly-once processing semantics, stateful Flink jobs, and schema evolution with Avro.",
                'prerequisites' => ['Data Engineering (Intermediate)', 'Java or Python streaming knowledge'],
                'learning_objectives' => [
                    'Architect high-throughput Kafka clusters with custom partitioners and consumer groups',
                    'Build stateful streaming applications with Apache Flink and exactly-once processing',
                    'Manage evolving data schemas with Confluent Schema Registry and Avro serialization',
                ],
                'skills_gained' => ['Apache Kafka', 'Apache Flink', 'Kafka Streams', 'Schema Registry', 'Exactly-Once Semantics'],
                'thumbnail' => 'https://images.unsplash.com/photo-1558494949-ef010cbdcc31?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Stateful Stream Processing & Event Systems',
                        'lessons' => [
                            ['title' => 'Kafka Cluster Internals, Partitioning & Consumer Groups', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'Stateful Stream Processing with Apache Flink & Windowing', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Real-Time Data Engineering Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Real-Time Fraud Detection Streaming Pipeline with Flink', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Data Warehousing',
                'slug' => 'data-warehousing',
                'category' => 'DATA ENGINEERING',
                'difficulty' => 'Advanced',
                'duration' => '10 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 24999,
                'description' => 'Cloud data warehouse design: Snowflake multi-cluster architecture, Google BigQuery partitioning/clustering, Kimball dimensional modeling, and SCD Types.',
                'full_description' => "Data Warehousing covers enterprise analytical storage: star schemas, snowflake schemas, Slowly Changing Dimensions (SCD Type 1, 2, 3), zero-copy cloning, and time travel in Snowflake.",
                'prerequisites' => ['Database Fundamentals', 'SQL Analytics'],
                'learning_objectives' => [
                    'Architect enterprise data warehouses in Snowflake and Google BigQuery',
                    'Implement Slowly Changing Dimensions (SCD Type 2) for historical auditing',
                    'Optimize query performance through clustering keys, partitioning, and materialized views',
                ],
                'skills_gained' => ['Snowflake Architecture', 'Google BigQuery', 'Kimball Methodology', 'SCD Type 2 Modeling', 'Warehouse Optimization'],
                'thumbnail' => 'https://images.unsplash.com/photo-1558494949-ef010cbdcc31?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Enterprise Cloud Data Warehousing',
                        'lessons' => [
                            ['title' => 'Snowflake & BigQuery Internal Architectures', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Dimensional Modeling, Fact Tables & Slowly Changing Dimensions', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Data Warehousing Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise Cloud Data Warehouse & Reporting Model', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Advanced Power BI & BI Engineering',
                'slug' => 'advanced-power-bi-bi-engineering',
                'category' => 'DATA ANALYST',
                'difficulty' => 'Advanced',
                'duration' => '10 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 22999,
                'description' => 'Advanced DAX tabular modeling, Composite models, Paginated Reports, Power BI Service administration, Row-Level Security, and CI/CD for BI.',
                'full_description' => "Advanced Power BI & BI Engineering teaches senior BI engineers to build scalable tabular models: complex virtual tables in DAX, XMLA endpoints, and dynamic Row-Level Security.",
                'prerequisites' => ['Data Analyst with SQL & Power BI (Intermediate)'],
                'learning_objectives' => [
                    'Write advanced DAX formulas using virtual tables (SUMX, CALCULATETABLE, ADDCOLUMNS)',
                    'Implement dynamic Row-Level Security (RLS) based on user credentials',
                    'Deploy enterprise BI assets with deployment pipelines and Tabular Editor',
                ],
                'skills_gained' => ['Advanced DAX', 'Tabular Editor', 'Row-Level Security (RLS)', 'Composite Models', 'Power BI Governance'],
                'thumbnail' => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Advanced DAX & Enterprise BI Governance',
                        'lessons' => [
                            ['title' => 'Advanced DAX Iterator Functions & Virtual Table Contexts', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Dynamic Row-Level Security & Power BI Service Administration', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Advanced Power BI Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise Global Analytics Suite with Dynamic RLS', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],

            // Cloud Advanced
            [
                'title' => 'Cloud Architecture',
                'slug' => 'cloud-architecture',
                'category' => 'CLOUD COMPUTING',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 29999,
                'description' => 'Enterprise Cloud Well-Architected frameworks, multi-region active-active design, disaster recovery architectures, and cloud financial governance (FinOps).',
                'full_description' => "Cloud Architecture prepares engineers for Principal Cloud Architect roles: designing resilient, cost-effective, high-throughput cloud systems across multiple regions.",
                'prerequisites' => ['AWS Cloud Engineering or Azure Cloud Engineering (Intermediate)'],
                'learning_objectives' => [
                    'Architect multi-region active-active architectures with sub-second RPO/RTO',
                    'Implement cloud cost governance and FinOps monitoring frameworks',
                    'Design Zero-Trust cloud network topologies and transit interconnects',
                ],
                'skills_gained' => ['Well-Architected Framework', 'Multi-Region Design', 'FinOps & Cost Governance', 'Disaster Recovery Architecture', 'Cloud Governance'],
                'thumbnail' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Well-Architected Multi-Region Infrastructure',
                        'lessons' => [
                            ['title' => 'Cloud Architecture Principles: Reliability & Multi-Region Active-Active', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'Zero-Trust Cloud Networking & FinOps Cost Governance', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Cloud Architecture Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise Multi-Region Cloud Migration Architecture', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'AWS Solutions Architecture',
                'slug' => 'aws-solutions-architecture',
                'category' => 'CLOUD COMPUTING',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 29999,
                'description' => 'AWS Certified Solutions Architect Professional level: Transit Gateway, AWS Organizations, EKS clusters, EventBridge event buses, and DynamoDB Global Tables.',
                'full_description' => "AWS Solutions Architecture covers senior-level AWS architecture: multi-account management with AWS Control Tower, cross-region replication, and serverless event streaming.",
                'prerequisites' => ['AWS Cloud Engineering (Intermediate)'],
                'learning_objectives' => [
                    'Design multi-account enterprise landing zones with AWS Control Tower',
                    'Architect global event-driven backends with EventBridge and DynamoDB Global Tables',
                    'Deploy production Kubernetes clusters on AWS EKS with Karpenter auto-scaling',
                ],
                'skills_gained' => ['AWS Transit Gateway', 'AWS Organizations / Control Tower', 'Amazon EKS', 'DynamoDB Global Tables', 'AWS EventBridge'],
                'thumbnail' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Enterprise AWS Solutions Architecture',
                        'lessons' => [
                            ['title' => 'Multi-Account AWS Landing Zones & Transit Gateway Peering', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'Event-Driven Architectures with EventBridge & Global DynamoDB', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'AWS Solutions Architecture Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Global Resilient Enterprise Application on AWS', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Azure Cloud Architecture',
                'slug' => 'azure-cloud-architecture',
                'category' => 'CLOUD COMPUTING',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 29999,
                'description' => 'Azure Landing Zones, Hub-Spoke network topology, Azure Kubernetes Service (AKS), ExpressRoute, Azure Synapse Analytics, and Zero-Trust architecture.',
                'full_description' => "Azure Cloud Architecture teaches enterprise cloud architects to build enterprise Azure foundations following Cloud Adoption Framework (CAF) guidelines.",
                'prerequisites' => ['Azure Cloud Engineering (Intermediate)'],
                'learning_objectives' => [
                    'Architect Azure Landing Zones and Hub-Spoke networking topologies',
                    'Deploy and secure production Azure Kubernetes Service (AKS) clusters',
                    'Integrate hybrid connectivity using Azure ExpressRoute and VPN gateways',
                ],
                'skills_gained' => ['Azure Landing Zones', 'Hub-Spoke Topology', 'Azure AKS', 'Azure ExpressRoute', 'Azure CAF Governance'],
                'thumbnail' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Enterprise Azure Landing Zones & Governance',
                        'lessons' => [
                            ['title' => 'Azure Cloud Adoption Framework (CAF) & Landing Zone Design', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'Hub-Spoke Networking, Azure Firewall & AKS Security', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Azure Cloud Architecture Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise Hybrid Cloud Architecture on Azure', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Cloud-Native Application Engineering',
                'slug' => 'cloud-native-application-engineering',
                'category' => 'CLOUD COMPUTING',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 27999,
                'description' => 'Building microservices on Kubernetes, Istio Service Mesh, gRPC communication, distributed tracing with OpenTelemetry, and 12-factor cloud apps.',
                'full_description' => "Cloud-Native Application Engineering covers the CNCF landscape: deploying microservices onto Kubernetes, configuring Istio traffic management, and instrumenting OpenTelemetry.",
                'prerequisites' => ['DevOps Engineering or Kubernetes experience'],
                'learning_objectives' => [
                    'Architect 12-factor cloud-native applications for container runtimes',
                    'Implement service-to-service traffic routing and mutual TLS using Istio Service Mesh',
                    'Instrument distributed observability using OpenTelemetry, Prometheus, and Jaeger',
                ],
                'skills_gained' => ['CNCF Ecosystem', 'Istio Service Mesh', 'OpenTelemetry Tracing', '12-Factor Apps', 'Kubernetes Deployments'],
                'thumbnail' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: CNCF Cloud-Native Ecosystem & Service Mesh',
                        'lessons' => [
                            ['title' => '12-Factor Cloud-Native Architecture & Container Lifecycle', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Istio Service Mesh: Traffic Routing & Mutual TLS (mTLS)', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Cloud-Native Engineering Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Production Cloud-Native Microservices with Istio & OpenTelemetry', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Kubernetes & Container Orchestration',
                'slug' => 'kubernetes-container-orchestration',
                'category' => 'CLOUD COMPUTING',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 27999,
                'description' => 'Advanced Kubernetes: custom controllers (CRDs), Helm charts, Ingress Controllers, StatefulSets, storage classes, and GitOps with ArgoCD.',
                'full_description' => "Kubernetes & Container Orchestration prepares Certified Kubernetes Administrators (CKA) and engineers to deploy, scale, and automate production clusters.",
                'prerequisites' => ['DevOps Engineering or Docker fundamentals'],
                'learning_objectives' => [
                    'Manage complex stateful applications using StatefulSets and persistent storage classes',
                    'Author reusable Helm charts and configure Ingress routing with TLS certificates',
                    'Implement declarative GitOps delivery pipelines using ArgoCD and Kubernetes operators',
                ],
                'skills_gained' => ['Kubernetes (CKA Level)', 'Helm Packaging', 'GitOps (ArgoCD)', 'Custom Resource Definitions (CRDs)', 'StatefulSets & Storage'],
                'thumbnail' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Production Kubernetes & GitOps',
                        'lessons' => [
                            ['title' => 'Kubernetes Cluster Internals: etcd, Kubelet & Control Plane', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'StatefulSets, Custom Resource Definitions & GitOps with ArgoCD', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Kubernetes Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Multi-Tenant GitOps Kubernetes Cluster Architecture', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'DevOps & SRE',
                'slug' => 'devops-sre',
                'category' => 'CLOUD COMPUTING',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Rajesh Kumar',
                'price' => 27999,
                'description' => 'Site Reliability Engineering (SRE): error budgets, SLI/SLO definition, chaos engineering with Litmus/Gremlin, Prometheus alerting, and incident postmortems.',
                'full_description' => "DevOps & SRE teaches Google-style Site Reliability Engineering: defining Service Level Objectives (SLOs), calculating error budgets, running blameless postmortems, and conducting chaos experiments.",
                'prerequisites' => ['DevOps Engineering (Intermediate)'],
                'learning_objectives' => [
                    'Formulate Service Level Indicators (SLIs) and Service Level Objectives (SLOs)',
                    'Manage error budgets and automate deployment rollbacks upon SLO breaches',
                    'Execute chaos engineering experiments to discover hidden single points of failure',
                ],
                'skills_gained' => ['SRE Principles', 'SLI / SLO / SLA Metrics', 'Chaos Engineering', 'Prometheus & Grafana', 'Incident Postmortems'],
                'thumbnail' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Site Reliability Engineering & Chaos Testing',
                        'lessons' => [
                            ['title' => 'SRE Foundations: SLIs, SLOs & Error Budget Governance', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Prometheus Metric Alerting & Chaos Engineering Experiments', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'DevOps & SRE Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise SRE Observability & Chaos Testing Framework', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],

            // Database Advanced
            [
                'title' => 'Database Administration & Performance Engineering',
                'slug' => 'database-administration-performance-engineering',
                'category' => 'DATABASE',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Aman Verma',
                'price' => 27999,
                'description' => 'High-performance database optimization: query execution tuning, index strategy engineering, buffer pool internals, database sharding, and high availability.',
                'full_description' => "Database Administration & Performance Engineering teaches database reliability engineers to identify query bottlenecks, optimize locking and concurrency, tune storage engines, and scale databases horizontally.",
                'prerequisites' => ['SQL DBA or Oracle DBA (Intermediate)'],
                'learning_objectives' => [
                    'Analyze execution plans to optimize index seeks and eliminate expensive table scans',
                    'Tune buffer pool memory, transaction log writes, and I/O bottlenecks',
                    'Design horizontal database sharding and multi-master replication topologies',
                ],
                'skills_gained' => ['Query Performance Tuning', 'Index Strategy Engineering', 'Concurrency & Locking', 'Database Sharding', 'Disaster Recovery'],
                'thumbnail' => 'https://images.unsplash.com/photo-1544383835-bda2bc66a55d?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Database Internals & Performance Tuning',
                        'lessons' => [
                            ['title' => 'Storage Engine Internals, B-Trees & Index Seek Optimization', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'Locking, Blocking, Deadlock Analysis & Sharding Strategies', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'DB Performance Engineering Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise High-Throughput Database Optimization Audit', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],

            // SAP Advanced
            [
                'title' => 'Advanced SAP FICO',
                'slug' => 'advanced-sap-fico',
                'category' => 'SAP',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 27999,
                'description' => 'Advanced Controlling (CO): Profit Center Accounting, Internal Orders, Product Costing (CO-PC), Profitability Analysis (CO-PA), and Financial Closing.',
                'full_description' => "Advanced SAP FICO trains senior SAP finance consultants in complex Controlling modules: product cost collectors, costing sheets, overhead calculation, and multi-dimensional profitability analysis.",
                'prerequisites' => ['SAP FICO/HANA (Intermediate)'],
                'learning_objectives' => [
                    'Configure Product Cost Controlling (CO-PC) with costing variants and valuation strategies',
                    'Implement Profitability Analysis (CO-PA) operating concerns and characteristic derivations',
                    'Manage period-end financial closing with SAP Financial Closing Cockpit',
                ],
                'skills_gained' => ['Product Costing (CO-PC)', 'Profitability Analysis (CO-PA)', 'Internal Orders', 'Financial Closing Cockpit', 'S/4HANA Universal Journal'],
                'thumbnail' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Product Costing & Profitability Analysis',
                        'lessons' => [
                            ['title' => 'Product Cost Controlling (CO-PC) Configuration & Calculations', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'Profitability Analysis (CO-PA) Operating Concerns & Derivations', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Advanced SAP FICO Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise Product Costing & CO-PA Implementation', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'SAP S/4HANA Finance',
                'slug' => 'sap-s-4hana-finance',
                'category' => 'SAP',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 29999,
                'description' => 'Universal Journal (ACDOCA), Central Finance architecture, cash management, real-time analytics with Embedded Analytics, and financial migration.',
                'full_description' => "SAP S/4HANA Finance teaches the revolutionary architectural changes of S/4HANA: ACDOCA single source of truth, Central Finance replication, and real-time Fiori reporting.",
                'prerequisites' => ['SAP FICO/HANA (Intermediate)'],
                'learning_objectives' => [
                    'Master Universal Journal (ACDOCA) table architecture and real-time reconciliation',
                    'Configure SAP Central Finance (cFIN) data replication and mapping',
                    'Implement SAP Cash Management and Bank Account Management in S/4HANA',
                ],
                'skills_gained' => ['ACDOCA Architecture', 'SAP Central Finance (cFIN)', 'Cash Management', 'Fiori Financial Analytics', 'S/4HANA Migration'],
                'thumbnail' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: S/4HANA Architecture & Central Finance',
                        'lessons' => [
                            ['title' => 'ACDOCA Universal Journal & Real-Time Finance Architecture', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'SAP Central Finance (cFIN) Architecture & Replication', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'S/4HANA Finance Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise S/4HANA Universal Journal Configuration', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'SAP S/4HANA Implementation',
                'slug' => 'sap-s-4hana-implementation',
                'category' => 'SAP',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 29999,
                'description' => 'SAP Activate Methodology, Business Blueprinting, Cutover Planning, Data Migration with LTMC/Migration Cockpit, and Go-Live Governance.',
                'full_description' => "SAP S/4HANA Implementation covers end-to-end project lifecycle management: Discover, Prepare, Explore (Fit-to-Standard), Realize, Deploy (Cutover), and Run phases.",
                'prerequisites' => ['SAP functional consultant experience (MM, PP, or FICO)'],
                'learning_objectives' => [
                    'Execute projects following the SAP Activate methodology and Fit-to-Standard workshops',
                    'Migrate legacy master and transactional data using SAP S/4HANA Migration Cockpit',
                    'Plan and execute detailed cutover schedules, testing cycles, and Go-Live governance',
                ],
                'skills_gained' => ['SAP Activate Methodology', 'Fit-to-Standard Workshops', 'Migration Cockpit (LTMC)', 'Cutover Management', 'Project Governance'],
                'thumbnail' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: SAP Activate Project Delivery',
                        'lessons' => [
                            ['title' => 'SAP Activate Methodology Phases & Fit-to-Standard Workshops', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'Data Migration with Migration Cockpit & Cutover Execution', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'S/4HANA Implementation Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Full SAP S/4HANA Transformation Project Blueprint', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'SAP Integration',
                'slug' => 'sap-integration',
                'category' => 'SAP',
                'difficulty' => 'Advanced',
                'duration' => '10 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 26999,
                'description' => 'Enterprise integration patterns, SAP Event Mesh, API Management, SAP Cloud Connector, B2B integration, and hybrid connectivity.',
                'full_description' => "SAP Integration teaches integration architects to build event-driven, decoupled enterprise ecosystems connecting S/4HANA with external SaaS platforms and cloud data lakes.",
                'prerequisites' => ['SAP BTP/CPI (Intermediate)'],
                'learning_objectives' => [
                    'Implement asynchronous event-driven integrations with SAP Event Mesh',
                    'Expose and secure S/4HANA OData services using SAP API Management',
                    'Configure secure on-premise to cloud tunnels with SAP Cloud Connector',
                ],
                'skills_gained' => ['SAP Event Mesh', 'SAP API Management', 'SAP Cloud Connector', 'Enterprise Integration Patterns', 'B2B Trading Partner'],
                'thumbnail' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Enterprise Event-Driven SAP Integration',
                        'lessons' => [
                            ['title' => 'SAP Event Mesh & Asynchronous Event-Driven Architectures', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'SAP API Management & Cloud Connector Security Topologies', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'SAP Integration Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise Multi-System Event-Driven Integration Suite', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'SAP BTP Architecture',
                'slug' => 'sap-btp-architecture',
                'category' => 'SAP',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 29999,
                'description' => 'Cloud Foundry & Kyma runtime, Multi-tenant SaaS architecture on BTP, SAP HANA Cloud, Identity Authentication Service (IAS), and BTP governance.',
                'full_description' => "SAP BTP Architecture covers enterprise cloud platform design: building multi-tenant SaaS extensions, deploying serverless Kyma microservices, and modeling SAP HANA Cloud data.",
                'prerequisites' => ['SAP BTP/CPI (Intermediate)'],
                'learning_objectives' => [
                    'Architect multi-tenant cloud extensions in SAP BTP Cloud Foundry and Kyma',
                    'Model calculations views and storage partitions in SAP HANA Cloud',
                    'Configure single sign-on and identity federation using SAP IAS and Entra ID',
                ],
                'skills_gained' => ['SAP BTP Architecture', 'Cloud Foundry & Kyma', 'SAP HANA Cloud', 'Identity Service (IAS)', 'Multi-Tenant SaaS Design'],
                'thumbnail' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Multi-Tenant BTP & HANA Cloud Architecture',
                        'lessons' => [
                            ['title' => 'BTP Global Accounts, Subaccounts, Cloud Foundry & Kyma', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'SAP HANA Cloud Modeling, Calculation Views & IAS Security', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'SAP BTP Architecture Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Enterprise Multi-Tenant SaaS Extension on SAP BTP', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Performance & Load Testing Engineering',
                'slug' => 'performance-load-testing-engineering',
                'category' => 'Quality Assurance & Testing',
                'difficulty' => 'Advanced',
                'duration' => '10 Weeks',
                'instructor' => 'Neha Patel',
                'price' => 24999,
                'description' => 'Enterprise load testing with JMeter, k6, Gatling, distributed test clusters, latency profiling, and bottleneck analysis.',
                'full_description' => "Performance & Load Testing Engineering prepares QA specialists to simulate hundreds of thousands of concurrent users, detect database locking bottlenecks, and assure system resilience under extreme load.",
                'prerequisites' => ['Automation Testing or API Testing (Intermediate)'],
                'learning_objectives' => [
                    'Write distributed load testing scripts in k6 (JavaScript) and JMeter',
                    'Analyze latency distribution, P95/P99 percentiles, and throughput degradation',
                    'Correlate system performance metrics (CPU, memory, database IOPS) during peak load',
                ],
                'skills_gained' => ['k6 Load Testing', 'Apache JMeter', 'Gatling', 'Latency Profiling (P99)', 'Bottleneck Analysis'],
                'thumbnail' => 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Distributed Load Testing & Latency Optimization',
                        'lessons' => [
                            ['title' => 'Load Testing Metrics: Throughput, Virtual Users & P99 Percentiles', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Distributed Load Generation with k6 & CI Pipeline Integration', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Performance Testing Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: High-Concurrency E-Commerce Load Test & Capacity Audit', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Advanced Mobile Engineering & Architecture',
                'slug' => 'advanced-mobile-engineering-architecture',
                'category' => 'Mobile Engineering',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Sarah Johnson',
                'price' => 27999,
                'description' => 'Kotlin Multiplatform (KMP), Swift/SwiftUI internals, Clean Architecture, CI/CD automation with Fastlane, and offline sync engines.',
                'full_description' => "Advanced Mobile Engineering & Architecture covers production mobile development at enterprise scale: shared business logic with Kotlin Multiplatform, modular architecture, and automated release pipelines.",
                'prerequisites' => ['Android Development or Mobile Applications (Intermediate)'],
                'learning_objectives' => [
                    'Architect cross-platform shared business logic with Kotlin Multiplatform (KMP)',
                    'Automate App Store and Google Play deployments using Fastlane and GitHub Actions',
                    'Implement offline-first data sync architectures with conflict resolution',
                ],
                'skills_gained' => ['Kotlin Multiplatform (KMP)', 'Fastlane CI/CD', 'Offline-First Sync', 'Mobile Security Hardening', 'Clean Architecture'],
                'thumbnail' => 'https://images.unsplash.com/photo-1512941937669-90a1b58e7e9c?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Enterprise Mobile Architecture & CI/CD',
                        'lessons' => [
                            ['title' => 'Kotlin Multiplatform Shared Logic & Modular Clean Architecture', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'Automating Store Deployments with Fastlane & Offline Sync', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Advanced Mobile Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Production Kotlin Multiplatform Mobile Application', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Design Systems & Enterprise Product Architecture',
                'slug' => 'design-systems-enterprise-product-architecture',
                'category' => 'Design & Creative',
                'difficulty' => 'Advanced',
                'duration' => '10 Weeks',
                'instructor' => 'Elena Rostova',
                'price' => 24999,
                'description' => 'Enterprise design token pipelines (Style Dictionary), multi-brand design systems, WCAG 2.2 AAA accessibility, and Figma REST API automation.',
                'full_description' => "Design Systems & Enterprise Product Architecture teaches design systems leads to architect multi-brand token pipelines, enforce strict WCAG AAA accessibility, and integrate Figma tokens directly into frontend repositories.",
                'prerequisites' => ['UI/UX Design (Intermediate)'],
                'learning_objectives' => [
                    'Architect automated Design Token pipelines using Style Dictionary and Figma API',
                    'Enforce WCAG 2.2 AAA accessibility compliance across complex component libraries',
                    'Establish design system governance, versioning, and developer documentation workflows',
                ],
                'skills_gained' => ['Design Tokens (Style Dictionary)', 'Figma API Automation', 'WCAG 2.2 AAA Compliance', 'Design System Governance', 'Multi-Brand Systems'],
                'thumbnail' => 'https://images.unsplash.com/photo-1581291518857-4e27b48ff24e?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Design Tokens & System Governance',
                        'lessons' => [
                            ['title' => 'Multi-Brand Design Tokens & Style Dictionary Transformation', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'WCAG AAA Accessibility & Component Library Automation', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Design Architecture Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Multi-Brand Enterprise Design System & Token Pipeline', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Growth Marketing & Marketing Analytics Engineering',
                'slug' => 'growth-marketing-analytics-engineering',
                'category' => 'Marketing & Business',
                'difficulty' => 'Advanced',
                'duration' => '10 Weeks',
                'instructor' => 'Kavita Menon',
                'price' => 24999,
                'description' => 'Multi-touch attribution modeling, Customer Data Platforms (CDPs / Segment), server-side tracking (CAPI), SQL for growth, and marketing automation.',
                'full_description' => "Growth Marketing & Marketing Analytics Engineering combines technical marketing with data engineering: server-side Meta Conversions API (CAPI), multi-touch Markov attribution models, and CDP architectures.",
                'prerequisites' => ['Digital Marketing (Intermediate)', 'SQL / Analytics basics'],
                'learning_objectives' => [
                    'Implement server-side conversion tracking via Meta CAPI and Google Tag Manager Server-Side',
                    'Model multi-touch attribution (Markov Chains, Shapley Value) in SQL and Python',
                    'Architect Customer Data Platforms (CDPs) with Segment and Reverse ETL tools (Census/Hightouch)',
                ],
                'skills_gained' => ['Server-Side Tracking (CAPI)', 'Multi-Touch Attribution', 'Customer Data Platforms (CDP)', 'Growth SQL Analytics', 'Reverse ETL'],
                'thumbnail' => 'https://images.unsplash.com/photo-1533750349088-cd871a92f312?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Technical Marketing & Attribution Engineering',
                        'lessons' => [
                            ['title' => 'Server-Side Tagging, CAPI & First-Party Data Architecture', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'Multi-Touch Attribution Modeling & Reverse ETL Workflows', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Growth Engineering Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Full-Funnel Marketing Analytics & Server-Side CDP Suite', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Healthcare Informatics & Clinical Data Architecture',
                'slug' => 'healthcare-informatics-clinical-data-architecture',
                'category' => 'Healthcare & Life Sciences',
                'difficulty' => 'Advanced',
                'duration' => '10 Weeks',
                'instructor' => 'Dr. R. K. Sharma',
                'price' => 26999,
                'description' => 'HL7/FHIR health data standards, Electronic Health Record (EHR) interoperability, HIPAA security rule technical safeguards, and clinical data pipelines.',
                'full_description' => "Healthcare Informatics & Clinical Data Architecture covers healthcare software engineering: implementing HL7 v2 and FHIR RESTful APIs, securing ePHI, and integrating clinical systems.",
                'prerequisites' => ['Medical Coding or Healthcare Fundamentals', 'API fundamentals'],
                'learning_objectives' => [
                    'Implement HL7 FHIR resource APIs (Patient, Encounter, Observation, Condition)',
                    'Design HIPAA-compliant architectures with encryption in transit and at rest',
                    'Build clinical data ingestion pipelines for EHR and laboratory data exchange',
                ],
                'skills_gained' => ['HL7 / FHIR Standards', 'EHR Interoperability', 'HIPAA Technical Safeguards', 'Clinical Data Pipelines', 'ePHI Security'],
                'thumbnail' => 'https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: FHIR Interoperability & Clinical Data Systems',
                        'lessons' => [
                            ['title' => 'HL7 FHIR Architecture, RESTful Resources & Data Modeling', 'type' => 'video', 'duration' => '30 min'],
                            ['title' => 'HIPAA Security Safeguards & Clinical Data Exchange Integration', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Health Informatics Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: HIPAA-Compliant FHIR Healthcare Data Gateway', 'type' => 'assignment', 'duration' => '90 min'],
                        ],
                    ],
                ],
            ],
            [
                'title' => 'Advanced Data Science & Statistical Learning',
                'slug' => 'advanced-data-science-statistical-learning',
                'category' => 'DATA SCIENCE',
                'difficulty' => 'Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Dr. Aris Thorne',
                'price' => 27999,
                'description' => 'Non-parametric statistics, Bayesian inference with PyMC, survival analysis, high-dimensional regularization, and causal inference.',
                'full_description' => "Advanced Data Science & Statistical Learning provides rigorous mathematical and computational training in Bayesian statistics, causal inference (DoWhy), and survival modeling.",
                'prerequisites' => ['Data Science (Intermediate)'],
                'learning_objectives' => [
                    'Implement Bayesian statistical modeling with PyMC and MCMC sampling',
                    'Conduct causal inference and potential outcomes estimation using DoWhy',
                    'Perform survival analysis and Cox Proportional Hazards modeling',
                ],
                'skills_gained' => ['Bayesian Inference (PyMC)', 'Causal Inference (DoWhy)', 'Survival Analysis', 'Non-Parametric Methods', 'Statistical Learning'],
                'thumbnail' => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=1200&q=80',
                'modules' => [
                    [
                        'title' => 'Module 1: Bayesian Inference & Causal Analysis',
                        'lessons' => [
                            ['title' => 'Bayesian Statistical Modeling & MCMC Sampling with PyMC', 'type' => 'video', 'duration' => '35 min'],
                            ['title' => 'Causal Inference, Propensity Matching & Survival Analysis', 'type' => 'text', 'duration' => '35 min'],
                            ['title' => 'Statistical Learning Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min'],
                            ['title' => 'Capstone: Bayesian Causal Inference & Lifetime Value Modeling', 'type' => 'assignment', 'duration' => '120 min'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
