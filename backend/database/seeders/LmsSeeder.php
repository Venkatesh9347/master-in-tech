<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Lesson;
use App\Models\Section;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\LessonResource;
use App\Models\Course;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class LmsSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $courses = Course::all();

        foreach ($courses as $course) {
            // Create default sections for each course
            $sections = match ($course->slug) {
                'full-stack-web-development' => [
                    [
                        'title' => 'Getting Started',
                        'slug' => 'getting-started',
                        'description' => 'Welcome to Full Stack Web Development. Start your journey here.',
                        'sort_order' => 0,
                    ],
                    [
                        'title' => 'Frontend Development',
                        'slug' => 'frontend-development',
                        'description' => 'Learn HTML, CSS, and React fundamentals.',
                        'sort_order' => 1,
                    ],
                    [
                        'title' => 'Backend Development',
                        'slug' => 'backend-development',
                        'description' => 'Learn server-side development with Node.js and databases.',
                        'sort_order' => 2,
                    ],
                ],
                'python-with-ai' => [
                    [
                        'title' => 'Python Fundamentals',
                        'slug' => 'python-fundamentals',
                        'description' => 'Start with Python basics.',
                        'sort_order' => 0,
                    ],
                    [
                        'title' => 'Data Science & ML',
                        'slug' => 'data-science-ml',
                        'description' => 'Explore data science and machine learning.',
                        'sort_order' => 1,
                    ],
                ],
                'sap-fico' => [
                    [
                        'title' => 'SAP FICO Introduction',
                        'slug' => 'sap-fico-introduction',
                        'description' => 'Learn SAP FICO fundamentals.',
                        'sort_order' => 0,
                    ],
                    [
                        'title' => 'Advanced SAP FICO',
                        'slug' => 'advanced-sap-fico',
                        'description' => 'Deep dive into SAP FICO.',
                        'sort_order' => 1,
                    ],
                ],
                default => [
                    [
                        'title' => 'Getting Started',
                        'slug' => 'getting-started-' . $course->id,
                        'description' => 'Welcome to this course.',
                        'sort_order' => 0,
                    ],
                ],
            };

            foreach ($sections as $index => $sectionData) {
                $section = Section::firstOrCreate(
                    [
                        'course_id' => $course->id,
                        'slug' => $sectionData['slug'],
                    ],
                    array_merge($sectionData, [
                        'course_id' => $course->id,
                        'is_published' => true,
                    ])
                );

                // Create lessons for the first section only (Getting Started) if none exist
                if ($index === 0 && $section->lessons()->count() === 0) {
                    $this->createIntroLessons($course, $section);
                }
            }
        }

        // Ensure lesson ID 1 exists for course 1 (backward compatibility)
        $existingLesson1 = Lesson::where('id', 1)->first();
        if (! $existingLesson1) {
            $section1 = Section::where('course_id', 1)
                ->orderBy('sort_order')
                ->first();

            if ($section1) {
                Lesson::create([
                    'section_id' => $section1->id,
                    'course_id' => 1,
                    'title' => 'Introduction to the Course',
                    'slug' => 'introduction',
                    'description' => 'Welcome to this course. This is the first lesson.',
                    'duration' => '5 min',
                    'type' => 'text',
                    'metadata' => json_encode([
                        'content' => '<h2>Welcome!</h2><p>This is the introductory lesson for the course.</p>',
                    ]),
                    'sort_order' => 0,
                    'is_published' => true,
                ]);
            }
        }
    }

    private function createIntroLessons(Course $course, Section $section): void
    {
        // Lesson 1: Video introduction
        $lesson1 = Lesson::create([
            'section_id' => $section->id,
            'course_id' => $course->id,
            'title' => 'Welcome to the Course',
            'slug' => 'welcome',
            'description' => 'An introductory video to the course.',
            'duration' => '5 min',
            'type' => 'video',
            'metadata' => json_encode([
                'video_url' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
                'video_title' => 'Course Introduction',
                'duration' => '05:00',
            ]),
            'sort_order' => 0,
            'is_published' => true,
        ]);

        LessonResource::create([
            'lesson_id' => $lesson1->id,
            'title' => 'Course Syllabus',
            'file_url' => '#',
            'file_size' => '150 KB',
            'description' => 'Download the full course syllabus.',
            'sort_order' => 0,
        ]);

        // Lesson 2: Text lesson
        Lesson::create([
            'section_id' => $section->id,
            'course_id' => $course->id,
            'title' => 'What You Will Learn',
            'slug' => 'what-you-will-learn',
            'description' => 'Overview of course outcomes.',
            'duration' => '3 min',
            'type' => 'text',
            'metadata' => json_encode([
                'content' => '<h2>What You Will Learn</h2><ul><li>Core concepts</li><li>Practical skills</li><li>Real-world projects</li></ul>',
            ]),
            'sort_order' => 1,
            'is_published' => true,
        ]);

        // Lesson 3: Quiz
        $quizLesson = Lesson::create([
            'section_id' => $section->id,
            'course_id' => $course->id,
            'title' => 'Knowledge Check',
            'slug' => 'knowledge-check',
            'description' => 'Test your understanding of the introduction.',
            'duration' => '10 min',
            'type' => 'quiz',
            'sort_order' => 2,
            'is_published' => true,
        ]);

        $quiz = Quiz::create([
            'lesson_id' => $quizLesson->id,
            'title' => 'Introduction Quiz',
            'description' => 'Check your understanding of the course introduction.',
            'time_limit' => 10,
            'passing_score' => 70,
            'randomize_questions' => false,
            'is_published' => true,
        ]);

        $q1 = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'What is the main focus of this course?',
            'marks' => 2,
            'sort_order' => 0,
        ]);
        QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Building practical tech skills', 'is_correct' => true, 'sort_order' => 0]);
        QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Cooking recipes', 'is_correct' => false, 'sort_order' => 1]);
        QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Gardening tips', 'is_correct' => false, 'sort_order' => 2]);

        $q2 = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'How many weeks does this course typically take?',
            'marks' => 3,
            'sort_order' => 1,
        ]);
        QuizOption::create(['question_id' => $q2->id, 'option_text' => '8-12 weeks', 'is_correct' => true, 'sort_order' => 0]);
        QuizOption::create(['question_id' => $q2->id, 'option_text' => '1-2 weeks', 'is_correct' => false, 'sort_order' => 1]);
        QuizOption::create(['question_id' => $q2->id, 'option_text' => '20+ weeks', 'is_correct' => false, 'sort_order' => 2]);

        // Lesson 4: Assignment
        Lesson::create([
            'section_id' => $section->id,
            'course_id' => $course->id,
            'title' => 'Setup Development Environment',
            'slug' => 'setup-dev-environment',
            'description' => 'Set up your development environment for the course.',
            'duration' => '15 min',
            'type' => 'assignment',
            'sort_order' => 3,
            'is_published' => true,
        ]);

        $assignment = Assignment::create([
            'lesson_id' => Lesson::where('course_id', $course->id)->where('section_id', $section->id)->where('sort_order', 3)->first()->id,
            'course_id' => $course->id,
            'title' => 'Setup Development Environment',
            'instructions' => 'Install the required tools and submit a screenshot of your development environment.',
            'max_marks' => 100,
            'is_published' => true,
        ]);
    }
}
