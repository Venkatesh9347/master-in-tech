<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\Faq;
use App\Models\HomeSection;
use App\Models\Instructor;
use App\Models\LearningPath;
use App\Models\NavigationItem;
use App\Models\Resource;
use App\Models\Testimonial;
use App\Models\WebsiteSetting;
use Illuminate\Database\Seeder;

class CmsContentSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Course Categories (in priority order)
        $categories = [
            ['name' => 'Artificial Intelligence', 'slug' => 'artificial-intelligence', 'icon' => '🤖', 'sort_order' => 1],
            ['name' => 'Machine Learning', 'slug' => 'machine-learning', 'icon' => '🧠', 'sort_order' => 2],
            ['name' => 'Data Science', 'slug' => 'data-science', 'icon' => '📊', 'sort_order' => 3],
            ['name' => 'Python with AI', 'slug' => 'python-with-ai', 'icon' => '🐍', 'sort_order' => 4],
            ['name' => 'SAP', 'slug' => 'sap', 'icon' => '🏢', 'sort_order' => 5],
            ['name' => 'Medical Coding', 'slug' => 'medical-coding', 'icon' => '🏥', 'sort_order' => 6],
            ['name' => 'Web Development', 'slug' => 'web-development', 'icon' => '🌐', 'sort_order' => 7],
            ['name' => 'Mobile App Development', 'slug' => 'mobile-app-development', 'icon' => '📱', 'sort_order' => 8],
            ['name' => 'Full Stack Development', 'slug' => 'full-stack-development', 'icon' => '⚡', 'sort_order' => 9],
            ['name' => 'Cyber Security', 'slug' => 'cyber-security', 'icon' => '🛡️', 'sort_order' => 10],
            ['name' => 'Cloud & DevOps', 'slug' => 'cloud-devops', 'icon' => '☁️', 'sort_order' => 11],
            ['name' => 'Database & SQL', 'slug' => 'database-sql', 'icon' => '🗄️', 'sort_order' => 12],
            ['name' => 'Python Programming', 'slug' => 'python-programming', 'icon' => '💻', 'sort_order' => 13],
        ];

        foreach ($categories as $cat) {
            CourseCategory::updateOrCreate(['slug' => $cat['slug']], $cat);
        }

        // 2. Instructors / Faculty
        $instructors = [
            [
                'name' => 'Sarah Johnson',
                'designation' => 'Senior Lead Architect',
                'company' => 'Microsoft',
                'bio' => '10+ years architecting enterprise web systems and cloud services. Passionate about TypeScript, React, and server actions.',
                'avatar' => '👩‍💻',
                'rating' => '4.9 ★ (320 reviews)',
                'graduates_count' => '1,400+ students',
                'experience_years' => '10+ Years',
                'skills' => ['Full Stack', 'Cloud & DevOps', 'TypeScript'],
                'social_links' => ['linkedin' => 'https://linkedin.com', 'twitter' => 'https://twitter.com'],
                'display_order' => 1,
            ],
            [
                'name' => 'Aman Verma',
                'designation' => 'Staff AI Research Lead',
                'company' => 'Google',
                'bio' => 'AI researcher and mentor with deep expertise in deep learning, transformer fine-tuning, and scalable inference pipelines.',
                'avatar' => '👨‍💻',
                'rating' => '4.9 ★ (410 reviews)',
                'graduates_count' => '2,100+ students',
                'experience_years' => '8+ Years',
                'skills' => ['Artificial Intelligence', 'Machine Learning', 'Python'],
                'social_links' => ['linkedin' => 'https://linkedin.com', 'github' => 'https://github.com'],
                'display_order' => 2,
            ],
            [
                'name' => 'Neha Patel',
                'designation' => 'Principal SAP Consultant',
                'company' => 'SAP Labs',
                'bio' => 'Certified SAP FICO and enterprise financial reporting veteran with 8+ years leading multinational ERP deployments.',
                'avatar' => '👩‍💼',
                'rating' => '4.8 ★ (190 reviews)',
                'graduates_count' => '950+ students',
                'experience_years' => '8+ Years',
                'skills' => ['SAP', 'Financial Accounting', 'Enterprise ERP'],
                'social_links' => ['linkedin' => 'https://linkedin.com'],
                'display_order' => 3,
            ],
            [
                'name' => 'Rajesh Kumar',
                'designation' => 'Principal Cloud Architect',
                'company' => 'Amazon Web Services',
                'bio' => 'AWS & Kubernetes certified infra specialist who has guided Fortune 100 enterprise migrations and CI/CD automation.',
                'avatar' => '👨‍💼',
                'rating' => '4.9 ★ (280 reviews)',
                'graduates_count' => '1,600+ students',
                'experience_years' => '11+ Years',
                'skills' => ['Cloud & DevOps', 'Kubernetes', 'AWS Architecture'],
                'social_links' => ['linkedin' => 'https://linkedin.com'],
                'display_order' => 4,
            ],
            [
                'name' => 'Priya Sharma',
                'designation' => 'Staff Data Scientist',
                'company' => 'Netflix',
                'bio' => 'Specialist in recommendation systems, experimentation analysis, and high-volume data visualization using Python and SQL.',
                'avatar' => '👩‍🔬',
                'rating' => '4.9 ★ (240 reviews)',
                'graduates_count' => '1,200+ students',
                'experience_years' => '7+ Years',
                'skills' => ['Data Science', 'Machine Learning', 'SQL'],
                'social_links' => ['linkedin' => 'https://linkedin.com'],
                'display_order' => 5,
            ],
            [
                'name' => 'Michael Chen',
                'designation' => 'Senior DevOps Specialist',
                'company' => 'GitHub',
                'bio' => 'Automation advocate focused on secure CI/CD pipelines, container orchestration, and developer productivity tooling.',
                'avatar' => '👨‍🔧',
                'rating' => '4.8 ★ (150 reviews)',
                'graduates_count' => '880+ students',
                'experience_years' => '9+ Years',
                'skills' => ['Cloud & DevOps', 'Cyber Security', 'Docker'],
                'social_links' => ['github' => 'https://github.com'],
                'display_order' => 6,
            ],
        ];

        foreach ($instructors as $inst) {
            Instructor::updateOrCreate(['name' => $inst['name']], $inst);
        }

        // 3. Learning Paths
        $paths = [
            [
                'title' => 'AI & Intelligent Systems Architect',
                'slug' => 'ai-intelligent-systems-architect',
                'description' => 'Comprehensive progression from Python programming to Machine Learning, Deep Neural Networks, and Generative AI microservices.',
                'icon' => '🤖',
                'difficulty' => 'Beginner to Advanced',
                'estimated_duration' => '6 Months',
                'display_order' => 1,
            ],
            [
                'title' => 'Enterprise Full Stack & Cloud DevOps Engineer',
                'slug' => 'fullstack-cloud-devops-engineer',
                'description' => 'Master modern frontend architectures, Node.js backend systems, Dockerized microservices, and automated Kubernetes cloud pipelines.',
                'icon' => '⚡',
                'difficulty' => 'Intermediate to Advanced',
                'estimated_duration' => '5 Months',
                'display_order' => 2,
            ],
            [
                'title' => 'Data Science & Enterprise Business Intelligence',
                'slug' => 'data-science-business-intelligence',
                'description' => 'End-to-end data pipelines, statistical modeling, database warehousing with SQL, and predictive analytics dashboards.',
                'icon' => '📊',
                'difficulty' => 'Beginner to Intermediate',
                'estimated_duration' => '4 Months',
                'display_order' => 3,
            ],
        ];

        foreach ($paths as $pathData) {
            $lp = LearningPath::updateOrCreate(['slug' => $pathData['slug']], $pathData);
            $courses = Course::where('is_published', true)->take(3)->pluck('id');
            if ($courses->isNotEmpty()) {
                $syncData = [];
                foreach ($courses as $idx => $cId) {
                    $syncData[$cId] = ['sort_order' => $idx + 1];
                }
                $lp->courses()->sync($syncData);
            }
        }

        // 4. Testimonials
        $testimonials = [
            [
                'student_name' => 'Rohan Mehta',
                'student_role_or_company' => 'Software Engineer @ Adobe',
                'course_title' => 'Artificial Intelligence & Deep Learning',
                'rating' => 5,
                'content' => 'The structured approach and mentor feedback transformed my career. I transitioned from QA into an AI Engineering role within 4 months.',
                'is_featured' => true,
                'display_order' => 1,
            ],
            [
                'student_name' => 'Ananya Sen',
                'student_role_or_company' => 'Cloud Consultant @ Deloitte',
                'course_title' => 'Cloud & DevOps Engineering',
                'rating' => 5,
                'content' => 'The hands-on capstones and live Kubernetes clusters gave me the exact enterprise confidence needed to crack high-paying cloud roles.',
                'is_featured' => true,
                'display_order' => 2,
            ],
            [
                'student_name' => 'Karthik Raja',
                'student_role_or_company' => 'Senior SAP Consultant @ Infosys',
                'course_title' => 'SAP Enterprise Architecture',
                'rating' => 5,
                'content' => 'MasterInTech provided the most complete SAP FICO practical sessions. The 1-on-1 career guidance made all the difference.',
                'is_featured' => true,
                'display_order' => 3,
            ],
        ];

        foreach ($testimonials as $t) {
            Testimonial::updateOrCreate(['student_name' => $t['student_name']], $t);
        }

        // 5. Frequently Asked Questions (FAQs)
        $faqs = [
            [
                'category' => 'Programs & Learning Experience',
                'question' => 'How are Master In Tech programs structured?',
                'answer' => 'Our programs combine on-demand structured technical modules with weekly live mentor-led masterclasses, hands-on quizzes, and production-grade capstone coding projects.',
                'display_order' => 1,
            ],
            [
                'category' => 'Programs & Learning Experience',
                'question' => 'Do I need prior programming experience to enroll?',
                'answer' => 'Beginner courses require zero prior background. Intermediate and advanced tracks list explicit technical prerequisites on their syllabus pages.',
                'display_order' => 2,
            ],
            [
                'category' => 'Programs & Learning Experience',
                'question' => 'What is the weekly time commitment required?',
                'answer' => 'Most students dedicate between 6 to 10 hours per week, allowing you to comfortably balance learning alongside full-time work or college studies.',
                'display_order' => 3,
            ],
            [
                'category' => 'Certifications & Career Desk',
                'question' => 'How does certificate verification work?',
                'answer' => 'Upon achieving 100% completion on all lessons, quizzes, and assignments, our system issues a cryptographically unique certificate code verifiable publicly at /verify-certificate.',
                'display_order' => 4,
            ],
            [
                'category' => 'Certifications & Career Desk',
                'question' => 'What career support is provided to students?',
                'answer' => 'Students in professional bootcamps receive 1-on-1 resume reviews, mock technical interview sessions with senior tech leads, and direct referrals to hiring partners.',
                'display_order' => 5,
            ],
            [
                'category' => 'Admissions & Demo Classes',
                'question' => 'How do I schedule a free live demo & counseling session?',
                'answer' => 'Submit an enquiry directly from any course page or homepage. Our academic advisors will contact you to review prerequisites and schedule a 1-on-1 walkthrough.',
                'display_order' => 6,
            ],
        ];

        foreach ($faqs as $faq) {
            Faq::updateOrCreate(['question' => $faq['question']], $faq);
        }

        // 6. Resources & Knowledge Base
        $resources = [
            [
                'title' => 'Full Stack & AI Developer Roadmap',
                'slug' => 'full-stack-ai-developer-roadmap',
                'description' => 'Step-by-step visual career guide from fundamentals to senior system design.',
                'icon' => '🗺️',
                'tag' => 'Career Roadmap',
                'type' => 'Guide',
                'author' => 'MasterInTech Faculty',
                'display_order' => 1,
            ],
            [
                'title' => 'Top 100 Technical Interview Questions',
                'slug' => 'top-100-technical-interview-questions',
                'description' => 'Curated data structures, algorithms, React, and Node.js interview questions with solutions.',
                'icon' => '💡',
                'tag' => 'Interview Prep',
                'type' => 'Cheatsheet',
                'author' => 'Senior Tech Leads',
                'display_order' => 2,
            ],
            [
                'title' => 'Docker, Kubernetes & Cloud Architecture Cheat Sheet',
                'slug' => 'docker-kubernetes-cloud-cheat-sheet',
                'description' => 'Quick command reference, manifest templates, and production best practices.',
                'icon' => '☁️',
                'tag' => 'DevOps Tooling',
                'type' => 'Cheatsheet',
                'author' => 'DevOps Team',
                'display_order' => 3,
            ],
            [
                'title' => 'Python & Generative AI Starter Notebooks',
                'slug' => 'python-generative-ai-starter-notebooks',
                'description' => 'Hands-on Jupyter notebooks for LLM prompt engineering, embeddings, and vector databases.',
                'icon' => '🤖',
                'tag' => 'AI & ML',
                'type' => 'Code Repo',
                'author' => 'AI Research Group',
                'display_order' => 4,
            ],
            [
                'title' => 'SAP FICO Configuration & Workflow Guide',
                'slug' => 'sap-fico-configuration-guide',
                'description' => 'Comprehensive enterprise accounting ledger setup and transaction code reference.',
                'icon' => '🏢',
                'tag' => 'Enterprise ERP',
                'type' => 'Documentation',
                'author' => 'SAP Practice Lead',
                'display_order' => 5,
            ],
        ];

        foreach ($resources as $res) {
            Resource::updateOrCreate(['slug' => $res['slug']], $res);
        }

        // 7. Website Settings
        $settings = [
            'site_name' => 'MasterInTech',
            'site_tagline' => 'Accelerate Your Tech Career With Mentor-Led Bootcamps',
            'contact_email' => 'admissions@masterintech.com',
            'contact_phone' => '+91 98765 43210',
            'whatsapp_number' => '+91 98765 43210',
            'address' => 'Silicon Valley & Tech Hubs Worldwide',
            'footer_text' => 'Empowering engineers and career changers worldwide with hands-on, mentor-led programs in AI, Cloud, Full Stack, Data, and Enterprise Systems.',
            'copyright_text' => '© ' . date('Y') . ' MasterInTech. All rights reserved.',
            'seo_title' => 'MasterInTech — Industry-Ready Tech Bootcamps & AI Courses',
            'seo_description' => 'Learn Artificial Intelligence, Machine Learning, Full Stack, Cloud, SAP, and Data Science with live masterclasses and verifiable certificates.',
            'social_twitter' => 'https://twitter.com',
            'social_linkedin' => 'https://linkedin.com',
            'social_youtube' => 'https://youtube.com',
            'social_github' => 'https://github.com',
            'graduates_badge_text' => 'Over 50,000+ graduates globally',
        ];

        foreach ($settings as $key => $val) {
            WebsiteSetting::set($key, $val);
        }

        // 8. Navigation Items (Header & Footer)
        $navItems = [
            // Header
            ['location' => 'header', 'label' => 'Courses', 'url' => '/courses', 'icon' => '📚', 'sort_order' => 1],
            ['location' => 'header', 'label' => 'Live Masterclasses', 'url' => '/events', 'icon' => '📅', 'sort_order' => 2],
            ['location' => 'header', 'label' => 'Faculty Mentors', 'url' => '/instructors', 'icon' => '👥', 'sort_order' => 3],
            ['location' => 'header', 'label' => 'Resources', 'url' => '/resources', 'icon' => '💡', 'sort_order' => 4],
            ['location' => 'header', 'label' => 'FAQs', 'url' => '/faq', 'icon' => '❓', 'sort_order' => 5],
            ['location' => 'header', 'label' => 'Verify Certificate', 'url' => '/verify-certificate', 'icon' => '🎓', 'sort_order' => 6],
            // Footer Quick Links
            ['location' => 'footer_learning', 'label' => 'Explore Courses', 'url' => '/courses', 'sort_order' => 1],
            ['location' => 'footer_learning', 'label' => 'Live Masterclasses', 'url' => '/events', 'sort_order' => 2],
            ['location' => 'footer_learning', 'label' => 'Expert Instructors', 'url' => '/instructors', 'sort_order' => 3],
            ['location' => 'footer_learning', 'label' => 'Free Resources', 'url' => '/resources', 'sort_order' => 4],
            ['location' => 'footer_learning', 'label' => 'Verify Certificate', 'url' => '/verify-certificate', 'sort_order' => 5],
            // Footer Support
            ['location' => 'footer_support', 'label' => 'About Us', 'url' => '/about', 'sort_order' => 1],
            ['location' => 'footer_support', 'label' => 'FAQs & Helpdesk', 'url' => '/faq', 'sort_order' => 2],
            ['location' => 'footer_support', 'label' => 'Contact Support', 'url' => '/contact', 'sort_order' => 3],
            ['location' => 'footer_support', 'label' => 'Admissions & Enquiry', 'url' => '/contact', 'sort_order' => 4],
        ];

        foreach ($navItems as $nav) {
            NavigationItem::updateOrCreate(
                ['location' => $nav['location'], 'label' => $nav['label']],
                $nav
            );
        }

        // 9. Home Page Sections CMS
        $sections = [
            [
                'section_key' => 'banner',
                'title' => '🚀 Admissions Open for 2026 Batch — Limited 1-on-1 Mentorship Seats Available',
                'subtitle' => null,
                'badge' => 'NEW',
                'content' => [
                    'cta_text' => 'Enquire Now',
                    'is_sticky' => true,
                ],
                'is_enabled' => true,
                'sort_order' => 1,
            ],
            [
                'section_key' => 'hero',
                'title' => 'Master In-Demand Tech Skills. Built for Real-World Engineering.',
                'subtitle' => 'Live mentor-led bootcamps, structured industry curriculums, hands-on capstones, and verifiable career credentials.',
                'badge' => '✨ Premier Tech Learning Platform',
                'content' => [
                    'primary_button_text' => 'Explore Courses',
                    'primary_button_url' => '/courses',
                    'secondary_button_text' => 'Enquire Now',
                    'highlight_stats' => [
                        ['number' => '50k+', 'label' => 'Graduates'],
                        ['number' => '95%', 'label' => 'Placement Rate'],
                        ['number' => '100+', 'label' => 'Industry Programs'],
                        ['number' => '4.9 ★', 'label' => 'Average Rating'],
                    ],
                ],
                'is_enabled' => true,
                'sort_order' => 2,
            ],
            [
                'section_key' => 'explore_courses',
                'title' => 'Explore Our Courses',
                'subtitle' => 'Master practical engineering skills across Artificial Intelligence, Cloud, Full Stack, Data Science, and SAP.',
                'badge' => 'Curriculum',
                'content' => [
                    'show_category_tabs' => true,
                    'items_per_page' => 12,
                ],
                'is_enabled' => true,
                'sort_order' => 3,
            ],
            [
                'section_key' => 'learning_paths',
                'title' => 'Structured Career Roadmaps',
                'subtitle' => 'Curated multi-course specializations designed to take you from foundational syntax to staff engineer level.',
                'badge' => 'Roadmaps',
                'content' => [],
                'is_enabled' => true,
                'sort_order' => 4,
            ],
            [
                'section_key' => 'instructors',
                'title' => 'Meet Our Industry Faculty',
                'subtitle' => 'Learn directly from staff architects, AI researchers, and engineering leaders at top global tech companies.',
                'badge' => 'Faculty',
                'content' => [],
                'is_enabled' => true,
                'sort_order' => 5,
            ],
            [
                'section_key' => 'testimonials',
                'title' => 'Student Stories & Career Transformations',
                'subtitle' => 'Hear directly from alumni who transitioned into high-impact engineering roles worldwide.',
                'badge' => 'Alumni Reviews',
                'content' => [],
                'is_enabled' => true,
                'sort_order' => 6,
            ],
            [
                'section_key' => 'events',
                'title' => 'Live Masterclasses & Workshops',
                'subtitle' => 'Join interactive weekend sessions and system design workshops with industry experts.',
                'badge' => 'Live Sessions',
                'content' => [],
                'is_enabled' => true,
                'sort_order' => 7,
            ],
            [
                'section_key' => 'faq',
                'title' => 'Frequently Asked Questions',
                'subtitle' => 'Everything you need to know about curriculum structure, demo classes, admissions, and certificates.',
                'badge' => 'Helpdesk',
                'content' => [],
                'is_enabled' => true,
                'sort_order' => 8,
            ],
            [
                'section_key' => 'cta',
                'title' => 'Ready to Build Your Engineering Career?',
                'subtitle' => 'Speak with our academic faculty advisors to select the right specialization for your goals.',
                'badge' => 'Admissions',
                'content' => [
                    'button_text' => 'Submit Course Enquiry →',
                ],
                'is_enabled' => true,
                'sort_order' => 9,
            ],
        ];

        foreach ($sections as $sec) {
            HomeSection::updateOrCreate(['section_key' => $sec['section_key']], $sec);
        }
    }
}
