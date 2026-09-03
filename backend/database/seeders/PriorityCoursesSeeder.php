<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonResource;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PriorityCoursesSeeder extends Seeder
{
    public function run(): void
    {
        $tutor = User::where('role', 'tutor')->first() ?? User::first();

        // 1. Complete Priority Course Catalog Definition (13 Primary Courses)
        $priorityCourses = [
            1 => [
                'slug' => 'artificial-intelligence',
                'title' => 'Artificial Intelligence',
                'category' => 'AI & ML',
                'priority' => 1,
                'difficulty' => 'Beginner to Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Dr. Andrew Collins',
                'thumbnail' => 'https://images.unsplash.com/photo-1677442136019-21780ecad995?auto=format&fit=crop&w=1200&q=80',
                'description' => 'Learn the foundations and practical applications of Artificial Intelligence, including intelligent systems, search, reasoning, machine learning concepts, neural networks, generative AI, and real-world AI applications.',
                'full_description' => 'Master the full spectrum of modern Artificial Intelligence from foundational logic and mathematics to cutting-edge deep learning, LLMs, and agentic workflows. Designed for developers and engineers aiming to lead in AI.',
                'prerequisites' => ['Basic programming knowledge (Python recommended)', 'High-school level mathematics and linear algebra', 'Curiosity about intelligent systems'],
                'learning_objectives' => [
                    'Understand core AI paradigms, state space search, and intelligent agent architectures',
                    'Master mathematical foundations including linear algebra, probability, and optimization',
                    'Build neural networks, CNNs, RNNs, and transformer architectures from scratch',
                    'Develop Generative AI and RAG applications using vector databases and LLM APIs',
                    'Implement production-ready AI agents and recommendation engines',
                ],
                'skills_gained' => ['Intelligent Systems', 'Linear Algebra for AI', 'Machine Learning', 'Deep Learning & PyTorch', 'Transformers', 'Generative AI & LLMs', 'Prompt Engineering', 'RAG & Vector DBs', 'AI Agents'],
                'modules' => [
                    [
                        'title' => 'Module 1 — AI Fundamentals',
                        'lessons' => [
                            ['title' => 'What is Artificial Intelligence', 'type' => 'video', 'duration' => '25 min', 'video_url' => 'https://www.youtube.com/watch?v=2ePf9rue1Ao', 'description' => 'Introduction to the concepts, definitions, and goals of artificial intelligence.'],
                            ['title' => 'History of AI & Key Milestones', 'type' => 'text', 'duration' => '20 min', 'description' => 'Evolution of AI from Turing and Dartmouth to modern deep learning.'],
                            ['title' => 'AI vs ML vs Deep Learning', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=k2P_pHQDlp0', 'description' => 'Understanding the hierarchy and distinctions between AI, ML, and DL.'],
                            ['title' => 'Types of AI: Narrow, General & Super AI', 'type' => 'text', 'duration' => '25 min', 'description' => 'Categorization of AI by capability and functionality.'],
                            ['title' => 'Real-World AI Applications & Case Studies', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=ad79nYk2keg', 'description' => 'How AI transforms healthcare, finance, transportation, and creative media.'],
                            ['title' => 'Intelligent Agents & Environment Types', 'type' => 'text', 'duration' => '25 min', 'description' => 'Agent architectures, sensors, actuators, and PEAS framework.'],
                            ['title' => 'AI Fundamentals Knowledge Check', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Assess your understanding of fundamental AI concepts.'],
                        ],
                    ],
                    [
                        'title' => 'Module 2 — Mathematics for AI',
                        'lessons' => [
                            ['title' => 'Linear Algebra: Vectors, Matrices & Tensors', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=fNk_zzaMoSs', 'description' => 'Matrix operations, dot products, eigenvalues, and high-dimensional representations.'],
                            ['title' => 'Probability & Bayes Theorem for AI', 'type' => 'text', 'duration' => '30 min', 'description' => 'Conditional probability, random variables, and Bayesian inference.'],
                            ['title' => 'Descriptive & Inferential Statistics', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=xxpc-HPKN28', 'description' => 'Distributions, variance, hypothesis testing, and central limit theorem.'],
                            ['title' => 'Calculus & Mathematical Functions in AI', 'type' => 'text', 'duration' => '25 min', 'description' => 'Derivatives, partial derivatives, and the chain rule.'],
                            ['title' => 'Optimization Basics: Gradient Descent & Loss Functions', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=IHZwWFHWa-w', 'description' => 'Loss landscapes, convex optimization, SGD, Adam, and learning rate dynamics.'],
                            ['title' => 'Mathematics for AI Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Test your mathematical foundations for AI.'],
                        ],
                    ],
                    [
                        'title' => 'Module 3 — Machine Learning Foundations',
                        'lessons' => [
                            ['title' => 'Supervised Learning: Regression & Classification', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=ukzFI9rgwfU', 'description' => 'Learning from labeled datasets to make continuous and discrete predictions.'],
                            ['title' => 'Unsupervised Learning: Clustering & Dimensionality', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=IUnkn8Y_c34', 'description' => 'Discovering hidden patterns, grouping, and dimensionality reduction.'],
                            ['title' => 'Reinforcement Learning Principles', 'type' => 'text', 'duration' => '30 min', 'description' => 'Reward functions, policy gradients, Q-learning, and Markov decision processes.'],
                            ['title' => 'Training, Validation, and Test Splitting', 'type' => 'text', 'duration' => '25 min', 'description' => 'Preventing data leakage and establishing reliable model validation.'],
                            ['title' => 'Feature Engineering & Label Encoding', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=68ABAU_V8qI', 'description' => 'Preparing raw signals into high-impact numerical features.'],
                            ['title' => 'Model Evaluation Metrics: Precision, Recall & F1', 'type' => 'text', 'duration' => '25 min', 'description' => 'Confusion matrices, ROC-AUC curves, and metric trade-offs.'],
                            ['title' => 'ML Foundations Lab Assignment', 'type' => 'assignment', 'duration' => '45 min', 'description' => 'Build a predictive model and submit performance evaluation metrics.'],
                        ],
                    ],
                    [
                        'title' => 'Module 4 — Deep Learning',
                        'lessons' => [
                            ['title' => 'Neural Networks & Perceptrons', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=aircAruvnKk', 'description' => 'Artificial neurons, synaptic weights, bias, and network layers.'],
                            ['title' => 'Activation Functions: ReLU, Sigmoid & GELU', 'type' => 'text', 'duration' => '25 min', 'description' => 'Non-linear transformations and vanishing gradient resolution.'],
                            ['title' => 'Forward Propagation & Loss Computation', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=IHZwWFHWa-w', 'description' => 'Computing layer outputs and calculating categorical cross-entropy.'],
                            ['title' => 'Backpropagation & Computational Graphs', 'type' => 'video', 'duration' => '40 min', 'video_url' => 'https://www.youtube.com/watch?v=Ilg3gGewQ5U', 'description' => 'Chain rule automatic differentiation and weight updates.'],
                            ['title' => 'Convolutional Neural Networks (CNN) for Vision', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=YRhxdVk_sIs', 'description' => 'Convolution kernels, pooling, feature maps, and image classification.'],
                            ['title' => 'Recurrent Neural Networks (RNN) & LSTMs', 'type' => 'text', 'duration' => '30 min', 'description' => 'Sequence modeling, memory cells, and time series handling.'],
                            ['title' => 'Transformers & Multi-Head Self-Attention', 'type' => 'video', 'duration' => '45 min', 'video_url' => 'https://www.youtube.com/watch?v=wjZofJX0v4U', 'description' => 'Attention is all you need: Query, Key, Value mechanics and scaled dot-product attention.'],
                            ['title' => 'Deep Learning Checkpoint Quiz', 'type' => 'quiz', 'duration' => '25 min', 'description' => 'Validate your understanding of deep neural architectures.'],
                        ],
                    ],
                    [
                        'title' => 'Module 5 — Generative AI',
                        'lessons' => [
                            ['title' => 'Generative AI Fundamentals & Latent Spaces', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=hfIUstzHs9A', 'description' => 'Generative vs discriminative models, autoregressive decoding, and sampling temperature.'],
                            ['title' => 'Large Language Models (LLM) Architecture', 'type' => 'text', 'duration' => '30 min', 'description' => 'Foundation models, pre-training, instruction fine-tuning, and RLHF.'],
                            ['title' => 'Prompt Engineering & In-Context Learning', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=jC4v5AS4RIM', 'description' => 'Zero-shot, few-shot, chain-of-thought prompting, and structured outputs.'],
                            ['title' => 'Text Embeddings & Semantic Search', 'type' => 'text', 'duration' => '25 min', 'description' => 'High-dimensional vector representations and cosine similarity.'],
                            ['title' => 'Vector Databases: ChromaDB, Pinecone & FAISS', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=klTvEwg3oJ4', 'description' => 'Indexing, vector storage, similarity search, and hybrid retrieval.'],
                            ['title' => 'Retrieval-Augmented Generation (RAG)', 'type' => 'video', 'duration' => '40 min', 'video_url' => 'https://www.youtube.com/watch?v=T-D1OfcDW1M', 'description' => 'Grounding LLMs with external knowledge bases to eliminate hallucinations.'],
                            ['title' => 'Autonomous AI Agents & Tool Calling', 'type' => 'text', 'duration' => '30 min', 'description' => 'ReAct loops, function calling, planning, and autonomous workflows.'],
                            ['title' => 'Generative AI Mastery Quiz', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Test your knowledge on LLMs, RAG, and AI agents.'],
                        ],
                    ],
                    [
                        'title' => 'Module 6 — AI Projects',
                        'lessons' => [
                            ['title' => 'Project 1: Intelligent Conversational AI Chatbot', 'type' => 'video', 'duration' => '45 min', 'video_url' => 'https://www.youtube.com/watch?v=pGOyw_M1mNE', 'description' => 'Build a context-aware assistant with streaming output and conversation memory.'],
                            ['title' => 'Project 2: Personalized Recommendation Engine', 'type' => 'text', 'duration' => '40 min', 'description' => 'Develop collaborative filtering and embedding-based content recommendations.'],
                            ['title' => 'Project 3: Enterprise Document Q&A with RAG', 'type' => 'video', 'duration' => '50 min', 'video_url' => 'https://www.youtube.com/watch?v=tcqEUSncn8I', 'description' => 'Ingest multi-page PDFs, compute embeddings, and build an interactive Q&A pipeline.'],
                            ['title' => 'Project 4: Autonomous Research Agent Capstone', 'type' => 'assignment', 'duration' => '90 min', 'description' => 'Implement an autonomous agent that searches the web, synthesizes facts, and generates reports.'],
                        ],
                    ],
                ],
            ],

            2 => [
                'slug' => 'machine-learning',
                'title' => 'Machine Learning',
                'category' => 'AI & ML',
                'priority' => 2,
                'difficulty' => 'Beginner to Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Dr. Elena Rostova',
                'thumbnail' => 'https://images.unsplash.com/photo-1555949963-ff9fe0c870eb?auto=format&fit=crop&w=1200&q=80',
                'description' => 'Build a strong foundation in machine learning, including supervised learning, unsupervised learning, feature engineering, model evaluation, and practical predictive modeling.',
                'full_description' => 'Master classical and modern machine learning algorithms using Python, NumPy, Pandas, and Scikit-Learn. Learn to engineer robust pipelines, tune hyperparameters, and deploy predictive systems.',
                'prerequisites' => ['Python basics', 'Basic algebra and matrix arithmetic'],
                'learning_objectives' => [
                    'Master NumPy and Pandas for data manipulation and preprocessing',
                    'Implement linear, logistic, tree-based, and ensemble algorithms',
                    'Build robust data preprocessing and feature engineering pipelines',
                    'Apply cross-validation, regularization, and hyperparameter optimization',
                    'Deploy production ML models with Scikit-learn',
                ],
                'skills_gained' => ['NumPy', 'Pandas', 'Scikit-Learn', 'Feature Engineering', 'Regression', 'Classification', 'Random Forests', 'SVM', 'Clustering', 'ML Pipelines'],
                'modules' => [
                    [
                        'title' => 'Module 1 — Python for ML & Scientific Libraries',
                        'lessons' => [
                            ['title' => 'Python for Machine Learning Environment Setup', 'type' => 'video', 'duration' => '25 min', 'video_url' => 'https://www.youtube.com/watch?v=7eh4d6sabA0', 'description' => 'Virtual environments, Jupyter Notebooks, and package management.'],
                            ['title' => 'NumPy Arrays & Vectorized Computation', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=QUT1VHiLmmI', 'description' => 'Array creation, slicing, broadcasting, and matrix multiplication.'],
                            ['title' => 'Pandas for Data Manipulation & Cleaning', 'type' => 'text', 'duration' => '30 min', 'description' => 'DataFrames, series, indexing, missing values, and group-by transformations.'],
                            ['title' => 'Data Preprocessing & Feature Scaling', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=68ABAU_V8qI', 'description' => 'StandardScaler, MinMaxScaler, RobustScaler, and categorical encoding.'],
                            ['title' => 'Feature Engineering Strategies', 'type' => 'text', 'duration' => '25 min', 'description' => 'Creating interaction terms, polynomial features, and domain-specific indicators.'],
                            ['title' => 'Data Preprocessing Lab Assessment', 'type' => 'assignment', 'duration' => '45 min', 'description' => 'Clean a raw tabular dataset and create preprocessed feature matrices.'],
                        ],
                    ],
                    [
                        'title' => 'Module 2 — Supervised Learning & Algorithms',
                        'lessons' => [
                            ['title' => 'Linear Regression & Cost Functions', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=E5RjzSK0fvY', 'description' => 'Ordinary least squares, gradient descent optimization, and regression metrics.'],
                            ['title' => 'Logistic Regression & Classification Boundaries', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=yIYKR4sgzI8', 'description' => 'Sigmoid activation, log loss, odds ratios, and binary/multiclass classification.'],
                            ['title' => 'Decision Trees: Gini Impurity & Entropy', 'type' => 'text', 'duration' => '30 min', 'description' => 'Tree splitting criteria, pruning, maximum depth, and interpretability.'],
                            ['title' => 'Random Forests & Ensemble Methods', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=J4Wdy0Wc_xQ', 'description' => 'Bagging, bootstrap aggregation, out-of-bag error, and feature importances.'],
                            ['title' => 'Support Vector Machines (SVM) & Kernels', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=efR1C6CvhmE', 'description' => 'Hyperplanes, maximum margins, soft margins, and RBF kernel transformations.'],
                            ['title' => 'Supervised Learning Checkpoint Quiz', 'type' => 'quiz', 'duration' => '25 min', 'description' => 'Test your mastery of supervised learning algorithms.'],
                        ],
                    ],
                    [
                        'title' => 'Module 3 — Unsupervised Learning & Clustering',
                        'lessons' => [
                            ['title' => 'K-Means Clustering & Elbow Method', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=4b5d3muPQmA', 'description' => 'Centroid initialization, inertia, silhouette scores, and cluster profiling.'],
                            ['title' => 'Hierarchical Clustering & DBSCAN', 'type' => 'text', 'duration' => '30 min', 'description' => 'Dendrograms, agglomerative clustering, and density-based spatial clustering.'],
                            ['title' => 'Dimensionality Reduction with PCA', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=FgakZw6K1QQ', 'description' => 'Principal component analysis, explained variance ratio, and scree plots.'],
                            ['title' => 'Anomaly Detection Techniques', 'type' => 'text', 'duration' => '25 min', 'description' => 'Isolation forests, one-class SVMs, and outlier filtering.'],
                            ['title' => 'Unsupervised Learning Checkpoint', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Check your clustering and dimensionality reduction concepts.'],
                        ],
                    ],
                    [
                        'title' => 'Module 4 — Model Optimization & Scikit-Learn Pipelines',
                        'lessons' => [
                            ['title' => 'Model Evaluation & Cross-Validation', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=fSytzGwwBVw', 'description' => 'K-Fold, Stratified K-Fold, TimeSeriesSplit, and metric evaluation.'],
                            ['title' => 'Hyperparameter Tuning: GridSearch & RandomizedSearch', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=Gol_qOfhft4', 'description' => 'Systematic parameter search, Bayesian optimization, and cross-validated scoring.'],
                            ['title' => 'Building Scikit-Learn Pipelines', 'type' => 'text', 'duration' => '35 min', 'description' => 'ColumnTransformer, custom transformers, and unified fit/predict workflows.'],
                            ['title' => 'Preventing Overfitting & Regularization', 'type' => 'text', 'duration' => '25 min', 'description' => 'L1 Lasso, L2 Ridge, ElasticNet, and early stopping.'],
                            ['title' => 'Pipeline Architecture Lab', 'type' => 'assignment', 'duration' => '60 min', 'description' => 'Construct an end-to-end Scikit-Learn pipeline from raw features to prediction.'],
                        ],
                    ],
                    [
                        'title' => 'Module 5 — Production Machine Learning Capstone Project',
                        'lessons' => [
                            ['title' => 'Project Scoping & Exploratory Analysis', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=-o3AxdVcUtQ', 'description' => 'Framing the business problem and formulating predictive goals.'],
                            ['title' => 'Feature Engineering & Baseline Benchmark', 'type' => 'text', 'duration' => '30 min', 'description' => 'Iterative feature extraction and establishing naive performance baselines.'],
                            ['title' => 'Model Selection, Ensembling & Serialization', 'type' => 'video', 'duration' => '40 min', 'video_url' => 'https://www.youtube.com/watch?v=mNf4hX0y7pI', 'description' => 'Model stacking, voting classifiers, and Joblib model serialization.'],
                            ['title' => 'Full Machine Learning Project Capstone', 'type' => 'assignment', 'duration' => '90 min', 'description' => 'Submit complete project code, serialized model, and executive performance report.'],
                        ],
                    ],
                ],
            ],

            3 => [
                'slug' => 'data-science',
                'title' => 'Data Science',
                'category' => 'Data Science',
                'priority' => 3,
                'difficulty' => 'Beginner to Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Priya Sharma',
                'thumbnail' => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=1200&q=80',
                'description' => 'Learn Python-based data science, statistics, data cleaning, exploratory data analysis, visualization, machine learning, and real-world data projects.',
                'full_description' => 'Comprehensive data science curriculum covering Python analytics, statistics, data visualization, SQL queries, feature engineering, and business intelligence reporting with Power BI.',
                'prerequisites' => ['Basic computer literacy', 'Interest in working with data'],
                'learning_objectives' => [
                    'Analyze complex business datasets using Python and Pandas',
                    'Apply statistical hypothesis testing and probability modeling',
                    'Create stunning visualizations with Matplotlib, Seaborn, and Power BI',
                    'Query relational databases using SQL for analytics',
                    'Build predictive models and present executive-level data insights',
                ],
                'skills_gained' => ['Python', 'NumPy & Pandas', 'Statistics & Probability', 'Matplotlib & Seaborn', 'SQL Analytics', 'Exploratory Data Analysis', 'Power BI / Tableau', 'Predictive Analytics'],
                'modules' => [
                    [
                        'title' => 'Module 1 — Python & Mathematical Foundations',
                        'lessons' => [
                            ['title' => 'Python for Data Science Fundamentals', 'type' => 'video', 'duration' => '25 min', 'video_url' => 'https://www.youtube.com/watch?v=kqtD5dpn9C8', 'description' => 'Variables, data structures, loops, and list comprehensions in Python.'],
                            ['title' => 'Vectorized Computation with NumPy', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=QUT1VHiLmmI', 'description' => 'Multidimensional arrays, slicing, boolean masking, and linear algebra.'],
                            ['title' => 'Pandas DataFrames for Data Science', 'type' => 'text', 'duration' => '30 min', 'description' => 'Loading CSVs, merging, reshaping, and aggregation methods.'],
                            ['title' => 'Descriptive Statistics & Data Distributions', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=xxpc-HPKN28', 'description' => 'Mean, median, IQR, variance, normal distributions, and skewness.'],
                            ['title' => 'Probability & Hypothesis Testing', 'type' => 'text', 'duration' => '25 min', 'description' => 'P-values, t-tests, ANOVA, and A/B testing methodology.'],
                            ['title' => 'Statistics & Python Checkpoint', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Evaluate your statistical and Python foundation.'],
                        ],
                    ],
                    [
                        'title' => 'Module 2 — Data Cleaning & Exploratory Data Analysis (EDA)',
                        'lessons' => [
                            ['title' => 'Data Cleaning: Handling Missing Data & Duplicates', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=bDhvCp3_lYw', 'description' => 'Imputation strategies, dropping values, and handling inconsistencies.'],
                            ['title' => 'Data Visualization with Matplotlib & Seaborn', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=DAQNHzOcO5A', 'description' => 'Histograms, scatter plots, box plots, heatmaps, and customized styling.'],
                            ['title' => 'Univariate & Bivariate Exploratory Analysis', 'type' => 'text', 'duration' => '30 min', 'description' => 'Feature correlation analysis, pair plots, and outlier detection.'],
                            ['title' => 'Automated EDA Tools & Profiling Reports', 'type' => 'text', 'duration' => '25 min', 'description' => 'Generating interactive exploratory reports with modern Python tools.'],
                            ['title' => 'EDA Case Study: Customer Churn Analysis', 'type' => 'assignment', 'duration' => '45 min', 'description' => 'Perform an exploratory data analysis on a real-world customer dataset.'],
                        ],
                    ],
                    [
                        'title' => 'Module 3 — SQL & Feature Engineering',
                        'lessons' => [
                            ['title' => 'SQL Queries for Data Science', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=HXV3zeRR3h4', 'description' => 'Writing efficient SELECT queries, WHERE filters, and sorting.'],
                            ['title' => 'SQL Aggregations, GROUP BY & Window Functions', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=Ww71knvhQ-s', 'description' => 'Partitioning, running totals, lead/lag functions, and ranking.'],
                            ['title' => 'Relational Joins & Subqueries', 'type' => 'text', 'duration' => '30 min', 'description' => 'Inner, outer, left, right joins, and CTEs for analytics.'],
                            ['title' => 'Feature Engineering & Data Transformation', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=68ABAU_V8qI', 'description' => 'One-hot encoding, target encoding, log transformations, and binning.'],
                            ['title' => 'SQL & Data Transformation Assessment', 'type' => 'quiz', 'duration' => '25 min', 'description' => 'Validate your SQL and feature engineering skills.'],
                        ],
                    ],
                    [
                        'title' => 'Module 4 — Machine Learning Fundamentals & BI',
                        'lessons' => [
                            ['title' => 'Predictive Modeling with Scikit-Learn', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=0Lt9w-BxKFQ', 'description' => 'Training regression and classification models on clean feature sets.'],
                            ['title' => 'Model Validation, Bias-Variance & Regularization', 'type' => 'text', 'duration' => '30 min', 'description' => 'Cross-validation, ROC curves, precision-recall trade-offs.'],
                            ['title' => 'Power BI / Tableau Business Intelligence Concepts', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=TmhQCQr_ebQ', 'description' => 'Connecting data sources, DAX calculations, and executive dashboard design.'],
                            ['title' => 'Communicating Data Insights to Stakeholders', 'type' => 'text', 'duration' => '25 min', 'description' => 'Storytelling with data, presentation structure, and actionable metrics.'],
                            ['title' => 'BI Dashboard Lab', 'type' => 'assignment', 'duration' => '45 min', 'description' => 'Create an interactive analytical dashboard and write insight summaries.'],
                        ],
                    ],
                    [
                        'title' => 'Module 5 — End-to-End Data Science Project',
                        'lessons' => [
                            ['title' => 'Project Ingestion, Cleaning & Data Pipeline', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=-o3AxdVcUtQ', 'description' => 'Building an automated pipeline from raw data to clean data store.'],
                            ['title' => 'Model Training, Hyperparameter Tuning & Evaluation', 'type' => 'text', 'duration' => '30 min', 'description' => 'Training multiple candidate models and tuning hyperparameters.'],
                            ['title' => 'Insight Synthesis & Executive Reporting', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=uK731U_tV3I', 'description' => 'Structuring data findings into business recommendations.'],
                            ['title' => 'End-to-End Data Science Capstone', 'type' => 'assignment', 'duration' => '90 min', 'description' => 'Submit complete project notebook, visualizations, and business presentation.'],
                        ],
                    ],
                ],
            ],

            4 => [
                'slug' => 'python-with-ai',
                'title' => 'Python with AI',
                'category' => 'AI & ML',
                'priority' => 4,
                'difficulty' => 'Beginner to Advanced',
                'duration' => '10 Weeks',
                'instructor' => 'Aman Verma',
                'thumbnail' => 'https://images.unsplash.com/photo-1526374965328-7f61d4dc18c5?auto=format&fit=crop&w=1200&q=80',
                'description' => 'Master Python programming while building AI-powered applications using modern AI libraries, APIs, automation, data processing, and machine learning techniques.',
                'full_description' => 'Learn core Python programming combined with modern AI capabilities. Master OOP, APIs, OpenAI API, LangChain concepts, prompt engineering, RAG, and automation scripts.',
                'prerequisites' => ['No prior programming experience required', 'A computer with Internet access'],
                'learning_objectives' => [
                    'Master modern Python syntax, functions, OOP, and exception handling',
                    'Work with APIs, JSON data, NumPy, and Pandas',
                    'Integrate OpenAI and open-source LLM APIs into Python applications',
                    'Implement Prompt Engineering, Vector Search, and RAG architectures',
                    'Build and deploy an automated AI Chatbot project',
                ],
                'skills_gained' => ['Python', 'OOP', 'APIs & Web Requests', 'NumPy & Pandas', 'OpenAI API', 'Prompt Engineering', 'RAG', 'AI Automation', 'Chatbot Development'],
                'modules' => [
                    [
                        'title' => 'Module 1 — Modern Python Foundations',
                        'lessons' => [
                            ['title' => 'Python Fundamentals & Environment Setup', 'type' => 'video', 'duration' => '25 min', 'video_url' => 'https://www.youtube.com/watch?v=kqtD5dpn9C8', 'description' => 'Variables, data types, operators, and control flow.'],
                            ['title' => 'Functions, Scope & Lambda Expressions', 'type' => 'text', 'duration' => '25 min', 'description' => 'Writing clean, modular, reusable Python functions.'],
                            ['title' => 'Object-Oriented Programming in Python', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=JeznW_7DlB0', 'description' => 'Classes, instances, inheritance, encapsulation, and methods.'],
                            ['title' => 'Modules, Packages & Virtual Environments', 'type' => 'text', 'duration' => '25 min', 'description' => 'Structuring Python code and managing dependencies.'],
                            ['title' => 'File Handling & Exception Handling', 'type' => 'text', 'duration' => '25 min', 'description' => 'Reading/writing files and robust try/except patterns.'],
                            ['title' => 'Python Core Mastery Checkpoint', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Assess your Python language fundamentals.'],
                        ],
                    ],
                    [
                        'title' => 'Module 2 — APIs & Data Processing',
                        'lessons' => [
                            ['title' => 'Consuming REST APIs in Python', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=qUe3_0U2Pfc', 'description' => 'Making HTTP requests, handling JSON payloads, and authentication headers.'],
                            ['title' => 'NumPy Arrays & Vectorized Calculations', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=QUT1VHiLmmI', 'description' => 'Numerical processing and mathematical array operations.'],
                            ['title' => 'Pandas for Data Manipulation', 'type' => 'text', 'duration' => '35 min', 'description' => 'Filtering, grouping, and transforming tabular datasets.'],
                            ['title' => 'Data Visualization with Matplotlib', 'type' => 'text', 'duration' => '30 min', 'description' => 'Plotting charts, styling graphs, and visual inspection.'],
                            ['title' => 'API & Data Processing Lab', 'type' => 'assignment', 'duration' => '45 min', 'description' => 'Fetch data from a public API, process with Pandas, and plot results.'],
                        ],
                    ],
                    [
                        'title' => 'Module 3 — Machine Learning & LLM Integration',
                        'lessons' => [
                            ['title' => 'Machine Learning Foundations for Python Developers', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=0Lt9w-BxKFQ', 'description' => 'Scikit-learn classification, regression, and model persistence.'],
                            ['title' => 'Integrating OpenAI & LLM APIs in Python', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=pGOyw_M1mNE', 'description' => 'Calling completions, chat models, and streaming tokens.'],
                            ['title' => 'Prompt Engineering Patterns & System Messages', 'type' => 'text', 'duration' => '30 min', 'description' => 'Few-shot examples, JSON structured outputs, and guardrails.'],
                            ['title' => 'Function Calling & Structured Tool Use', 'type' => 'text', 'duration' => '30 min', 'description' => 'Allowing LLMs to invoke Python functions dynamically.'],
                            ['title' => 'LLM Integration Assessment', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Test your API and LLM integration knowledge.'],
                        ],
                    ],
                    [
                        'title' => 'Module 4 — AI Automation & Agentic RAG',
                        'lessons' => [
                            ['title' => 'Vector Embeddings & Semantic Search in Python', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=ySus5ZS0b94', 'description' => 'Generating embeddings and calculating similarity matrices.'],
                            ['title' => 'Vector Databases with ChromaDB & FAISS', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=klTvEwg3oJ4', 'description' => 'Storing and querying document vectors locally in Python.'],
                            ['title' => 'Building a RAG Pipeline in Python', 'type' => 'video', 'duration' => '40 min', 'video_url' => 'https://www.youtube.com/watch?v=T-D1OfcDW1M', 'description' => 'Document chunking, embedding, vector retrieval, and augmented prompt generation.'],
                            ['title' => 'AI Automation Workflows & Task Execution', 'type' => 'text', 'duration' => '30 min', 'description' => 'Automating report generation, email drafts, and web scraping.'],
                            ['title' => 'AI Automation Lab Assignment', 'type' => 'assignment', 'duration' => '60 min', 'description' => 'Build a Python script that ingests documents and answers domain queries.'],
                        ],
                    ],
                    [
                        'title' => 'Module 5 — AI Chatbot Capstone Project',
                        'lessons' => [
                            ['title' => 'Designing Chatbot Architecture & Conversation State', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=pGOyw_M1mNE', 'description' => 'Managing history, user sessions, and context limits.'],
                            ['title' => 'Building a Streamlit / Web UI for the AI Bot', 'type' => 'video', 'duration' => '40 min', 'video_url' => 'https://www.youtube.com/watch?v=D0D4Pa22iG0', 'description' => 'Rapid prototyping with Streamlit interactive chat interface.'],
                            ['title' => 'Deploying the Python AI Application', 'type' => 'text', 'duration' => '30 min', 'description' => 'Environment secrets, cloud deployment, and production scaling.'],
                            ['title' => 'Complete AI Chatbot Capstone Project', 'type' => 'assignment', 'duration' => '90 min', 'description' => 'Submit full project repository, UI walkthrough, and live bot link.'],
                        ],
                    ],
                ],
            ],

            5 => [
                'slug' => 'sap-fico',
                'title' => 'SAP',
                'category' => 'SAP',
                'priority' => 5,
                'difficulty' => 'Beginner to Advanced',
                'duration' => '8 Weeks',
                'instructor' => 'Neha Patel',
                'thumbnail' => 'https://images.unsplash.com/photo-1507679799987-c73779587ccf?auto=format&fit=crop&w=1200&q=80',
                'description' => 'Build a foundation in SAP enterprise systems, navigation, business processes, modules, configuration concepts, integration, and practical SAP scenarios.',
                'full_description' => 'Comprehensive SAP training covering ERP architecture, SAP S/4HANA navigation, SAP FICO (Financial & Controlling), MM (Materials Management), SD (Sales & Distribution), and enterprise configuration.',
                'prerequisites' => ['Basic understanding of business operations and finance fundamentals'],
                'learning_objectives' => [
                    'Navigate SAP S/4HANA GUI and Fiori launchpad with confidence',
                    'Master SAP Financial Accounting (General Ledger, AP, AR, Asset Accounting)',
                    'Configure Management Controlling (Cost Centers, Internal Orders)',
                    'Understand SAP MM and SD procurement and sales integration',
                    'Execute real-world SAP business transactions and financial closing',
                ],
                'skills_gained' => ['SAP S/4HANA', 'SAP Navigation', 'General Ledger (FI-GL)', 'Accounts Payable (FI-AP)', 'Accounts Receivable (FI-AR)', 'Asset Accounting (FI-AA)', 'Controlling (CO)', 'SAP MM & SD', 'Enterprise Configuration'],
                'modules' => [
                    [
                        'title' => 'Module 1 — SAP Fundamentals & Enterprise Navigation',
                        'lessons' => [
                            ['title' => 'Introduction to SAP ERP & SAP S/4HANA Innovations', 'type' => 'video', 'duration' => '25 min', 'video_url' => 'https://www.youtube.com/watch?v=78-9wZ8gE1s', 'description' => 'ERP evolution, in-memory computing, and S/4HANA capabilities.'],
                            ['title' => 'SAP GUI & Fiori Launchpad Interface Navigation', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=FqP_J2m5o8A', 'description' => 'Transaction codes (T-codes), menus, favorites, and Fiori apps.'],
                            ['title' => 'SAP Architecture & Client-Server Infrastructure', 'type' => 'text', 'duration' => '30 min', 'description' => 'Presentation, application, and database tiers in SAP.'],
                            ['title' => 'Organizational Structures: Client, Company Code, Plant', 'type' => 'text', 'duration' => '25 min', 'description' => 'Defining enterprise hierarchy and master data relationships.'],
                            ['title' => 'SAP Navigation & Architecture Checkpoint', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Assess your SAP fundamentals and navigation knowledge.'],
                        ],
                    ],
                    [
                        'title' => 'Module 2 — SAP Financial Accounting (FI)',
                        'lessons' => [
                            ['title' => 'Financial Accounting Overview & General Ledger (FI-GL)', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=Xh0Yq_1vW3c', 'description' => 'Chart of accounts, GL accounts, journal entries, and document posting.'],
                            ['title' => 'Accounts Payable (FI-AP): Vendor Master & Invoicing', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=eK2x1j6bE4o', 'description' => 'Vendor account groups, invoice verification, and payment runs.'],
                            ['title' => 'Accounts Receivable (FI-AR): Customer Master & Billing', 'type' => 'text', 'duration' => '35 min', 'description' => 'Customer master data, incoming payments, and dunning.'],
                            ['title' => 'Asset Accounting (FI-AA) & Depreciation Runs', 'type' => 'text', 'duration' => '30 min', 'description' => 'Asset classes, depreciation keys, acquisition, and retirement.'],
                            ['title' => 'Financial Closing & Balance Sheet Reporting', 'type' => 'text', 'duration' => '30 min', 'description' => 'Period-end closing tasks, trial balances, and financial statements.'],
                            ['title' => 'Financial Accounting Checkpoint Quiz', 'type' => 'quiz', 'duration' => '25 min', 'description' => 'Test your knowledge on SAP FI modules and processes.'],
                        ],
                    ],
                    [
                        'title' => 'Module 3 — Management Controlling (CO)',
                        'lessons' => [
                            ['title' => 'Controlling Fundamentals & Cost Element Accounting', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=P_X9g6y8E3s', 'description' => 'Primary vs secondary cost elements and controlling area setup.'],
                            ['title' => 'Cost Center Accounting (CO-CCA) & Allocations', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=8wY9mZ7kX2A', 'description' => 'Cost centers, activity types, statistical key figures, and distributions.'],
                            ['title' => 'Internal Orders (CO-IO): Planning & Settlement', 'type' => 'text', 'duration' => '30 min', 'description' => 'Real vs statistical internal orders and settlement rules.'],
                            ['title' => 'Profitability Analysis (CO-PA) Overview', 'type' => 'text', 'duration' => '25 min', 'description' => 'Market segment analysis and contribution margin reporting.'],
                            ['title' => 'Controlling Lab Exercises', 'type' => 'assignment', 'duration' => '45 min', 'description' => 'Configure cost centers and execute cost allocation cycles.'],
                        ],
                    ],
                    [
                        'title' => 'Module 4 — Cross-Module Logistics Integration (MM & SD)',
                        'lessons' => [
                            ['title' => 'SAP Materials Management (MM) Overview & P2P', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=6vG9wX1qL2A', 'description' => 'Procure-to-pay workflow, purchase requisitions, and purchase orders.'],
                            ['title' => 'SAP Sales & Distribution (SD) Overview & O2C', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=4xY1wZ8kE3s', 'description' => 'Order-to-cash workflow, sales orders, delivery, and billing.'],
                            ['title' => 'Integration Concepts: FI-MM and FI-SD Automatic Posting', 'type' => 'text', 'duration' => '30 min', 'description' => 'Account determination (OBYC, VKOA) and seamless posting.'],
                            ['title' => 'SAP Configuration Concepts (IMG / SPRO)', 'type' => 'text', 'duration' => '30 min', 'description' => 'Implementation Guide navigation and customizing tables.'],
                            ['title' => 'Integration & Configuration Checkpoint', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Validate your cross-module integration understanding.'],
                        ],
                    ],
                    [
                        'title' => 'Module 5 — Real-World SAP Implementation & Scenarios',
                        'lessons' => [
                            ['title' => 'Real-World SAP Business Scenarios & Case Studies', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=78-9wZ8gE1s', 'description' => 'End-to-end business transactions in multinational organizations.'],
                            ['title' => 'End-to-End Enterprise Process Execution', 'type' => 'text', 'duration' => '35 min', 'description' => 'Walking through complete order, fulfillment, invoicing, and reconciliation.'],
                            ['title' => 'SAP Troubleshooting & Best Practices', 'type' => 'text', 'duration' => '25 min', 'description' => 'Resolving common posting errors and document reversals.'],
                            ['title' => 'SAP Enterprise Configuration Capstone', 'type' => 'assignment', 'duration' => '90 min', 'description' => 'Configure enterprise business processes and document audit results.'],
                        ],
                    ],
                ],
            ],

            6 => [
                'slug' => 'medical-coding',
                'title' => 'Medical Coding',
                'category' => 'Healthcare',
                'priority' => 6,
                'difficulty' => 'Beginner to Advanced',
                'duration' => '10 Weeks',
                'instructor' => 'Dr. R. K. Sharma',
                'thumbnail' => 'https://images.unsplash.com/photo-1576091160399-112ba8d25d1d?auto=format&fit=crop&w=1200&q=80',
                'description' => 'Learn the foundations of medical coding, healthcare terminology, coding systems, documentation concepts, compliance, and practical coding workflows.',
                'full_description' => 'Industry-aligned healthcare curriculum covering medical terminology, anatomy review, ICD-10-CM diagnostic coding, CPT/HCPCS procedural coding, and medical billing basics. For educational training only; does not provide medical advice.',
                'prerequisites' => ['High school diploma or equivalent', 'Basic interest in healthcare administration and revenue cycle'],
                'learning_objectives' => [
                    'Understand medical terminology, prefixes, suffixes, and body systems',
                    'Abstract diagnostic codes accurately using ICD-10-CM guidelines',
                    'Assign procedural codes using CPT and HCPCS Level II code sets',
                    'Understand medical billing cycles, CMS-1500 claims, and reimbursement',
                    'Ensure HIPAA compliance and clinical documentation integrity',
                ],
                'skills_gained' => ['Medical Terminology', 'Human Anatomy', 'ICD-10-CM Coding', 'CPT Procedural Coding', 'HCPCS Level II', 'Medical Billing Basics', 'HIPAA Compliance', 'Chart Auditing'],
                'modules' => [
                    [
                        'title' => 'Module 1 — Medical Terminology & Anatomy Fundamentals',
                        'lessons' => [
                            ['title' => 'Medical Terminology: Roots, Prefixes & Suffixes', 'type' => 'video', 'duration' => '25 min', 'video_url' => 'https://www.youtube.com/watch?v=048pQ6xT8-w', 'description' => 'Deconstructing complex clinical terms into basic anatomical components.'],
                            ['title' => 'Human Anatomy Fundamentals for Coders', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=uBGl2BujkPQ', 'description' => 'Organ systems, body planes, directional terms, and clinical physiology.'],
                            ['title' => 'Healthcare Documentation & Medical Records', 'type' => 'text', 'duration' => '30 min', 'description' => 'EHR structures, progress notes, operative reports, and discharge summaries.'],
                            ['title' => 'HIPAA Compliance, Privacy & Security Rules', 'type' => 'text', 'duration' => '25 min', 'description' => 'Protected Health Information (PHI) guidelines and legal compliance.'],
                            ['title' => 'Terminology & Anatomy Checkpoint', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Assess your clinical vocabulary and anatomical foundations.'],
                        ],
                    ],
                    [
                        'title' => 'Module 2 — ICD-10-CM Diagnostic Coding',
                        'lessons' => [
                            ['title' => 'ICD-10-CM Structure & Navigation', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=yD8-8k6jE3s', 'description' => 'Alphabetic Index, Tabular List, Conventions, and instructional notes.'],
                            ['title' => 'General Coding Guidelines & Sequencing', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=8wY9mZ7kX2A', 'description' => 'Principal diagnosis selection, acute vs chronic conditions, and combo codes.'],
                            ['title' => 'Diagnosis Coding: Infectious, Circulatory & Respiratory', 'type' => 'text', 'duration' => '30 min', 'description' => 'Chapter-specific coding guidelines and mandatory secondary codes.'],
                            ['title' => 'Injuries, 7th Character Extensions & Z-Codes', 'type' => 'text', 'duration' => '30 min', 'description' => 'Initial encounter, subsequent encounter, sequela, and external causes.'],
                            ['title' => 'Diagnostic Coding Practice Lab', 'type' => 'assignment', 'duration' => '45 min', 'description' => 'Abstract and assign ICD-10-CM codes for five clinical case notes.'],
                        ],
                    ],
                    [
                        'title' => 'Module 3 — CPT & HCPCS Procedural Coding',
                        'lessons' => [
                            ['title' => 'Current Procedural Terminology (CPT) Concepts', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=6vG9wX1qL2A', 'description' => 'Category I, II, and III CPT codes format and organizational structure.'],
                            ['title' => 'Evaluation & Management (E/M) Coding Guidelines', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=4xY1wZ8kE3s', 'description' => 'Medical Decision Making (MDM) levels and time-based code selection.'],
                            ['title' => 'Surgical, Radiology & Laboratory Coding', 'type' => 'text', 'duration' => '30 min', 'description' => 'Global surgical package, separate procedures, and panel tests.'],
                            ['title' => 'CPT Modifiers & Bundling Edits (NCCI)', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=78-9wZ8gE1s', 'description' => 'Using modifiers 25, 59, 26, TC, and 50 correctly.'],
                            ['title' => 'HCPCS Level II National Codes', 'type' => 'text', 'duration' => '25 min', 'description' => 'Coding durable medical equipment (DME), supplies, and injectables.'],
                            ['title' => 'Procedural Coding Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Validate your procedural coding and modifier knowledge.'],
                        ],
                    ],
                    [
                        'title' => 'Module 4 — Medical Billing, Reimbursement & Compliance',
                        'lessons' => [
                            ['title' => 'Medical Billing Basics & Revenue Cycle Workflow', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=eK2x1j6bE4o', 'description' => 'Patient registration, charge capture, claim submission, and adjudication.'],
                            ['title' => 'CMS-1500 & UB-04 Claim Forms', 'type' => 'text', 'duration' => '30 min', 'description' => 'Field-by-field requirements for professional and institutional claims.'],
                            ['title' => 'Explanation of Benefits (EOB) & Remittance Advice', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=Xh0Yq_1vW3c', 'description' => 'Payer adjudication, allowed amounts, co-pays, deductibles, and co-insurance.'],
                            ['title' => 'Claim Denials, Appeals & Compliance Audits', 'type' => 'text', 'duration' => '25 min', 'description' => 'CARC/RARC reason codes, correcting rejected claims, and OIG compliance.'],
                            ['title' => 'Billing & Compliance Quiz', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Test your understanding of billing cycles and claim compliance.'],
                        ],
                    ],
                    [
                        'title' => 'Module 5 — Practical Medical Coding Practice & Scenarios',
                        'lessons' => [
                            ['title' => 'Practical Clinical Chart Abstracting Methodology', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=uBGl2BujkPQ', 'description' => 'Step-by-step extraction of diagnoses and procedures from authentic charts.'],
                            ['title' => 'Outpatient Multi-Specialty Coding Scenarios', 'type' => 'text', 'duration' => '35 min', 'description' => 'Cardiology, Orthopedics, Pediatrics, and Dermatology case studies.'],
                            ['title' => 'Physician Query Formulation & Documentation Integrity', 'type' => 'text', 'duration' => '25 min', 'description' => 'Writing compliant, non-leading physician queries for ambiguous charts.'],
                            ['title' => 'Comprehensive Medical Coding Capstone Assessment', 'type' => 'assignment', 'duration' => '90 min', 'description' => 'Abstract and code three complete clinical charts and submit your audit report.'],
                        ],
                    ],
                ],
            ],

            7 => [
                'slug' => 'web-development-fundamentals',
                'title' => 'Web Development',
                'category' => 'Full Stack',
                'priority' => 7,
                'difficulty' => 'Beginner to Advanced',
                'duration' => '10 Weeks',
                'instructor' => 'Sarah Johnson',
                'thumbnail' => 'https://images.unsplash.com/photo-1547658719-da2b51169166?auto=format&fit=crop&w=1200&q=80',
                'description' => 'Learn modern frontend and web development foundations, responsive UI design, DOM manipulation, APIs, and modern web application workflows.',
                'full_description' => 'Learn modern web development from HTML5, CSS3, and JavaScript (ES6+) to React, REST APIs, Git/GitHub, responsive design, and production frontend deployment.',
                'prerequisites' => ['No programming experience required', 'A modern web browser and text editor (VS Code)'],
                'learning_objectives' => [
                    'Write semantic, accessible HTML5 and modern CSS3 layouts',
                    'Master Flexbox, CSS Grid, media queries, and responsive web design',
                    'Program interactive web applications with modern JavaScript ES6+',
                    'Fetch and consume REST APIs using Async/Await and JSON',
                    'Build single-page web applications with React components and hooks',
                ],
                'skills_gained' => ['HTML5 & Semantic Web', 'CSS3 & Responsive Design', 'Flexbox & CSS Grid', 'JavaScript (ES6+)', 'DOM Manipulation', 'REST APIs', 'Git & GitHub', 'React Fundamentals', 'Frontend Deployment'],
                'modules' => [
                    [
                        'title' => 'Module 1 — HTML & Semantic Web',
                        'lessons' => [
                            ['title' => 'HTML5 Structure & Semantic Layout', 'type' => 'video', 'duration' => '25 min', 'video_url' => 'https://www.youtube.com/watch?v=kUMe1FH4CHE', 'description' => 'Document structure, tags, accessibility landmarks, and SEO meta tags.'],
                            ['title' => 'HTML Forms, Input Validation & Accessibility', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=fNcJuPIZ2WE', 'description' => 'Form controls, ARIA attributes, semantic inputs, and client validation.'],
                            ['title' => 'Semantic Elements & Best Practices', 'type' => 'text', 'duration' => '25 min', 'description' => 'Proper heading hierarchy, article vs section, and semantic clean code.'],
                            ['title' => 'HTML Semantic Checkpoint Quiz', 'type' => 'quiz', 'duration' => '15 min', 'description' => 'Test your core HTML semantics and structure.'],
                        ],
                    ],
                    [
                        'title' => 'Module 2 — CSS & Responsive Design',
                        'lessons' => [
                            ['title' => 'CSS Fundamentals & The Box Model', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=1PnVor36_40', 'description' => 'Selectors, specificity, margin, padding, border, and content box.'],
                            ['title' => 'CSS Flexbox Layout Mastery', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=phWxA89Lu94', 'description' => 'Flex containers, directions, alignment, justify-content, and wrap.'],
                            ['title' => 'CSS Grid Layouts & Template Areas', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=rg7Fvvl3taU', 'description' => 'Grid tracks, repeat, minmax, auto-fit, and grid template areas.'],
                            ['title' => 'Responsive Design & Media Queries', 'type' => 'text', 'duration' => '30 min', 'description' => 'Mobile-first breakpoints, viewport units, and responsive typography.'],
                            ['title' => 'Responsive Landing Page Lab', 'type' => 'assignment', 'duration' => '45 min', 'description' => 'Build a responsive multi-section landing page using modern CSS.'],
                        ],
                    ],
                    [
                        'title' => 'Module 3 — Modern JavaScript & DOM',
                        'lessons' => [
                            ['title' => 'JavaScript (ES6+) Core Syntax & Types', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=W6NZfCO5SIk', 'description' => 'Variables, arrow functions, template literals, destructuring, and spread.'],
                            ['title' => 'DOM Selection & Dynamic Manipulation', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=y17RuWkWdn8', 'description' => 'QuerySelector, creating elements, modifying classes, and innerHTML.'],
                            ['title' => 'Event Handling & Interactive UI', 'type' => 'text', 'duration' => '30 min', 'description' => 'Click events, input listeners, event delegation, and preventDefault.'],
                            ['title' => 'JavaScript DOM Checkpoint', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Evaluate your JavaScript and DOM manipulation skills.'],
                        ],
                    ],
                    [
                        'title' => 'Module 4 — APIs & Version Control with Git',
                        'lessons' => [
                            ['title' => 'Asynchronous JavaScript: Promises & Async/Await', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=PoRJizFvM7s', 'description' => 'Event loop, asynchronous calls, error handling with try/catch.'],
                            ['title' => 'Consuming REST APIs with the Fetch API', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=cuEtnrL9-H0', 'description' => 'GET, POST requests, parsing JSON, and dynamic rendering.'],
                            ['title' => 'Git & GitHub Workflows for Developers', 'type' => 'text', 'duration' => '30 min', 'description' => 'Repositories, commits, branching, pull requests, and merge conflicts.'],
                            ['title' => 'Interactive API Web App Lab', 'type' => 'assignment', 'duration' => '45 min', 'description' => 'Build a web application that fetches and filters live data from a public API.'],
                        ],
                    ],
                    [
                        'title' => 'Module 5 — React Fundamentals & Advanced Concepts',
                        'lessons' => [
                            ['title' => 'Introduction to React, JSX & Components', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=SqcY0GlETPk', 'description' => 'Component tree, JSX syntax, props, and rendering.'],
                            ['title' => 'State Management with useState & useEffect', 'type' => 'video', 'duration' => '40 min', 'video_url' => 'https://www.youtube.com/watch?v=O6P86uwfdR0', 'description' => 'Component state, side effects, dependency arrays, and lifecycle.'],
                            ['title' => 'React Advanced Concepts: Custom Hooks & Context', 'type' => 'text', 'duration' => '30 min', 'description' => 'Extracting reusable logic, global state context, and memoization.'],
                            ['title' => 'React Knowledge Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Test your React components and hooks understanding.'],
                        ],
                    ],
                    [
                        'title' => 'Module 6 — Frontend Project Capstone',
                        'lessons' => [
                            ['title' => 'Application Architecture & Component Design', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=w7ejDZ8SWv8', 'description' => 'Structuring folders, routing, and styling with TailwindCSS.'],
                            ['title' => 'State Integration, Search & Filtering Logic', 'type' => 'text', 'duration' => '30 min', 'description' => 'Handling user input, pagination, and persistent state.'],
                            ['title' => 'Building & Deploying the Production Web App', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=8JJ101D3knE', 'description' => 'Vite build optimization, asset compression, and Vercel/Netlify deploy.'],
                            ['title' => 'Complete Web Development Capstone Project', 'type' => 'assignment', 'duration' => '90 min', 'description' => 'Submit complete project codebase and live production URL.'],
                        ],
                    ],
                ],
            ],

            8 => [
                'slug' => 'mobile-app-development-fundamentals',
                'title' => 'Mobile App Development',
                'category' => 'Full Stack',
                'priority' => 8,
                'difficulty' => 'Beginner to Advanced',
                'duration' => '10 Weeks',
                'instructor' => 'David Lee',
                'thumbnail' => 'https://images.unsplash.com/photo-1512941937669-90a1b58e7e9c?auto=format&fit=crop&w=1200&q=80',
                'description' => 'Learn mobile app development for iOS and Android, covering cross-platform UI architectures, state management, mobile APIs, and store deployment.',
                'full_description' => 'Master native and modern mobile application development covering Android Architecture, Kotlin programming, UI building with Jetpack Compose / XML, SQLite/Room local storage, Firebase, and app store deployment.',
                'prerequisites' => ['Basic programming fundamentals in any object-oriented language'],
                'learning_objectives' => [
                    'Understand mobile operating system architectures and lifecycle events',
                    'Program idiomatic Kotlin for Android development',
                    'Design responsive, touch-friendly mobile user interfaces',
                    'Persist data locally using SQLite / Room database and SharedPreferences',
                    'Integrate REST APIs, Firebase authentication, and cloud messaging',
                    'Prepare, test, sign, and publish mobile applications',
                ],
                'skills_gained' => ['Android Architecture', 'Kotlin', 'Jetpack Compose', 'Mobile UI/UX', 'Retrofit API Integration', 'Room Local Storage', 'Firebase Auth & DB', 'Testing & Publishing'],
                'modules' => [
                    [
                        'title' => 'Module 1 — Mobile Development Fundamentals & Setup',
                        'lessons' => [
                            ['title' => 'Mobile Architecture & Android Ecosystem', 'type' => 'video', 'duration' => '25 min', 'video_url' => 'https://www.youtube.com/watch?v=fis26HvvDAg', 'description' => 'Android OS architecture, ART runtime, and application sandbox.'],
                            ['title' => 'Android Studio Setup & Emulator Configuration', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=EExSSotojVI', 'description' => 'Setting up IDE, SDKs, Gradle build system, and virtual devices.'],
                            ['title' => 'App Components: Activities, Services & Manifest', 'type' => 'text', 'duration' => '25 min', 'description' => 'AndroidManifest.xml, permissions, intents, and activity lifecycles.'],
                            ['title' => 'Mobile Fundamentals Checkpoint', 'type' => 'quiz', 'duration' => '15 min', 'description' => 'Assess your understanding of core mobile app architecture.'],
                        ],
                    ],
                    [
                        'title' => 'Module 2 — Kotlin Programming for Mobile',
                        'lessons' => [
                            ['title' => 'Kotlin Syntax, Null Safety & Data Types', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=F9UC9DY-vIU', 'description' => 'Val/var, safe calls (?.), elvis operator (?:), and smart casting.'],
                            ['title' => 'Object-Oriented Kotlin: Data Classes & Interfaces', 'type' => 'text', 'duration' => '30 min', 'description' => 'Constructors, inheritance, companion objects, and sealed classes.'],
                            ['title' => 'Kotlin Coroutines for Background Tasks', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=ZTDX43dChEg', 'description' => 'Suspend functions, dispatchers, scopes, and asynchronous flows.'],
                            ['title' => 'Kotlin for Android Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Test your Kotlin language proficiency.'],
                        ],
                    ],
                    [
                        'title' => 'Module 3 — Mobile UI Development & Navigation',
                        'lessons' => [
                            ['title' => 'UI Layouts & Material Design Components', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=bbMsuI2p1DQ', 'description' => 'ConstraintLayout, Buttons, TextInputs, and Material theming.'],
                            ['title' => 'Modern UI with Jetpack Compose', 'type' => 'video', 'duration' => '40 min', 'video_url' => 'https://www.youtube.com/watch?v=cDabx3SjuOY', 'description' => 'Declarative UI, State, Recomposition, and Compose modifiers.'],
                            ['title' => 'Dynamic Lists with RecyclerView & LazyColumn', 'type' => 'text', 'duration' => '30 min', 'description' => 'ViewHolders, adapters, diff utils, and smooth scrolling.'],
                            ['title' => 'App Navigation & Passing Data Between Screens', 'type' => 'text', 'duration' => '25 min', 'description' => 'Jetpack Navigation graph, NavHost, and deep linking.'],
                            ['title' => 'Mobile UI Design Lab', 'type' => 'assignment', 'duration' => '45 min', 'description' => 'Build a multi-screen mobile UI with interactive list navigation.'],
                        ],
                    ],
                    [
                        'title' => 'Module 4 — APIs, Storage, Auth & Firebase',
                        'lessons' => [
                            ['title' => 'Networking with Retrofit & OkHttp in Mobile', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=53BsyxwPhJk', 'description' => 'API interfaces, converters (Gson/Moshi), and response handling.'],
                            ['title' => 'Local Data Persistence with Room Database', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=lwAvI3WDXBY', 'description' => 'Entities, DAOs, Room database builder, and offline caching.'],
                            ['title' => 'Authentication & Realtime Cloud with Firebase', 'type' => 'text', 'duration' => '30 min', 'description' => 'Firebase Email/Google Auth, Firestore sync, and security rules.'],
                            ['title' => 'Networking & Storage Assessment', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Validate your mobile data and API integration skills.'],
                        ],
                    ],
                    [
                        'title' => 'Module 5 — Testing, Publishing & Mobile Project',
                        'lessons' => [
                            ['title' => 'Mobile App Testing: Unit & UI Espresso Tests', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=p4vW7N_W9yM', 'description' => 'Writing unit tests with JUnit and automated UI tests.'],
                            ['title' => 'Publishing Concepts & Google Play Store Guidelines', 'type' => 'text', 'duration' => '30 min', 'description' => 'Keystore signing, ProGuard/R8 shrinking, and Play Console release tracks.'],
                            ['title' => 'Production Mobile Application Capstone', 'type' => 'assignment', 'duration' => '90 min', 'description' => 'Submit complete native mobile project with source code and demo video.'],
                        ],
                    ],
                ],
            ],

            9 => [
                'slug' => 'full-stack-web-development',
                'title' => 'Full Stack Development',
                'category' => 'Full Stack',
                'priority' => 9,
                'difficulty' => 'Beginner to Advanced',
                'duration' => '12 Weeks',
                'instructor' => 'Sarah Johnson',
                'thumbnail' => 'https://images.unsplash.com/photo-1498050108023-c5249f4df085?auto=format&fit=crop&w=1200&q=80',
                'description' => 'Learn modern frontend and backend development, databases, APIs, authentication, deployment, and full-stack application development.',
                'full_description' => 'End-to-end full-stack software engineering. Master React on the frontend, Node.js and Express.js on the backend, SQL and NoSQL database modeling, JWT authentication, and production DevOps deployment.',
                'prerequisites' => ['Basic computer skills and logical thinking'],
                'learning_objectives' => [
                    'Build interactive single-page applications with React and TailwindCSS',
                    'Architect scalable RESTful APIs using Node.js and Express.js',
                    'Design relational (PostgreSQL/MySQL) and document (MongoDB) databases',
                    'Implement secure authentication, authorization, and role-based access',
                    'Deploy production full-stack web applications to cloud servers',
                ],
                'skills_gained' => ['HTML/CSS/JS', 'React', 'Node.js', 'Express.js', 'REST APIs', 'JWT Auth', 'PostgreSQL / MySQL', 'MongoDB', 'Git & GitHub', 'Full-Stack Deployment'],
                'modules' => [
                    [
                        'title' => 'Module 1 — Frontend Engineering (HTML, CSS, JS & React)',
                        'lessons' => [
                            ['title' => 'Modern Frontend Stack Overview & Architecture', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=w7ejDZ8SWv8', 'description' => 'SPA architecture, component thinking, and modern asset bundling.'],
                            ['title' => 'JavaScript ES6+ for Full Stack Developers', 'type' => 'text', 'duration' => '30 min', 'description' => 'Async programming, closures, event loops, and module systems.'],
                            ['title' => 'Building Dynamic UIs with React Components & State', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=SqcY0GlETPk', 'description' => 'Props, state hooks, side effects, and reusable component libraries.'],
                            ['title' => 'Client-Side Routing & Global State Architecture', 'type' => 'text', 'duration' => '30 min', 'description' => 'React Router dynamic params, context providers, and form management.'],
                            ['title' => 'Frontend Engineering Checkpoint', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Test your core frontend engineering principles.'],
                        ],
                    ],
                    [
                        'title' => 'Module 2 — Backend Engineering (Node.js & Express.js)',
                        'lessons' => [
                            ['title' => 'Node.js Runtime, Event Loop & Non-Blocking I/O', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=TlB_eWDSMt4', 'description' => 'Node architecture, npm packages, fs module, and event-driven servers.'],
                            ['title' => 'Express.js Framework & Middleware Architecture', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=L72fhGm1tfE', 'description' => 'Application routing, body parsers, custom middleware, and error handlers.'],
                            ['title' => 'RESTful API Design Principles & HTTP Status Codes', 'type' => 'text', 'duration' => '30 min', 'description' => 'Designing clean resource URIs, idempotency, and standardized responses.'],
                            ['title' => 'Request Validation & Sanitization with Joi / Zod', 'type' => 'text', 'duration' => '25 min', 'description' => 'Schema validation, stripping unknown keys, and secure payload handling.'],
                            ['title' => 'Backend API Development Lab', 'type' => 'assignment', 'duration' => '45 min', 'description' => 'Build a complete REST API with Express including CRUD operations and validation.'],
                        ],
                    ],
                    [
                        'title' => 'Module 3 — Authentication & Authorization',
                        'lessons' => [
                            ['title' => 'Authentication Fundamentals & Password Hashing', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=27duWqXzQ04', 'description' => 'Bcrypt hashing, salt rounds, rainbow table defense, and user registration.'],
                            ['title' => 'JSON Web Tokens (JWT) & Token Lifecycles', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=7Q17ubqL29k', 'description' => 'Access tokens, refresh tokens, signing algorithms, and authorization headers.'],
                            ['title' => 'Role-Based Access Control (RBAC) Implementation', 'type' => 'text', 'duration' => '30 min', 'description' => 'Protecting student, tutor, and admin endpoints with granular permissions.'],
                            ['title' => 'API Security: CORS, Rate Limiting & Helmet', 'type' => 'text', 'duration' => '25 min', 'description' => 'Defending against DDoS, cross-origin threats, and header exploits.'],
                            ['title' => 'Auth & Security Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Evaluate your authentication and authorization knowledge.'],
                        ],
                    ],
                    [
                        'title' => 'Module 4 — Database Design (SQL & NoSQL)',
                        'lessons' => [
                            ['title' => 'Relational Database Modeling with PostgreSQL/MySQL', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=HXV3zeRR3h4', 'description' => 'Foreign keys, one-to-many, many-to-many relations, and migrations.'],
                            ['title' => 'Document Database Design with MongoDB', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=ofme2o29ngU', 'description' => 'Collections, documents, sub-documents, references, and aggregation pipelines.'],
                            ['title' => 'ORM Integration with Prisma / TypeORM / Eloquent', 'type' => 'text', 'duration' => '30 min', 'description' => 'Schema definition, seeding, query builders, and eager loading.'],
                            ['title' => 'Transactions (ACID), Indexing & Optimization', 'type' => 'text', 'duration' => '25 min', 'description' => 'Database indexing, composite keys, and transaction rollback handling.'],
                            ['title' => 'Database Architecture Assessment', 'type' => 'assignment', 'duration' => '45 min', 'description' => 'Design an enterprise database schema and implement migrations.'],
                        ],
                    ],
                    [
                        'title' => 'Module 5 — Development Tools & API Testing',
                        'lessons' => [
                            ['title' => 'Professional Developer Tools: VS Code & Git', 'type' => 'text', 'duration' => '20 min', 'description' => 'Linting with ESLint, code formatting with Prettier, and Git branch workflows.'],
                            ['title' => 'Automated API Testing with Postman & Supertest', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=CLG0ep4vY10', 'description' => 'Writing automated integration tests for backend API routes.'],
                            ['title' => 'Environment Configuration & Secrets Security', 'type' => 'text', 'duration' => '25 min', 'description' => 'Managing .env variables, staging vs production configs, and key rotation.'],
                            ['title' => 'Tooling & Testing Checkpoint Quiz', 'type' => 'quiz', 'duration' => '15 min', 'description' => 'Test your developer tools and API testing workflows.'],
                        ],
                    ],
                    [
                        'title' => 'Module 6 — Full-Stack Capstone Project',
                        'lessons' => [
                            ['title' => 'Full-Stack Architecture & System Design', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=nu_pCVPKzTk', 'description' => 'Designing data models, API contracts, and responsive UI components.'],
                            ['title' => 'Building the Secure Backend & Database Layer', 'type' => 'text', 'duration' => '35 min', 'description' => 'Implementing authenticated controllers, services, and queries.'],
                            ['title' => 'Connecting React Client & Managing Global State', 'type' => 'video', 'duration' => '40 min', 'video_url' => 'https://www.youtube.com/watch?v=0riHps91AzE', 'description' => 'Axios API interceptors, error handling toasts, and optimistic updates.'],
                            ['title' => 'Cloud Deployment & Production Launch', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=8JJ101D3knE', 'description' => 'Deploying backend APIs and frontend bundle with HTTPS.'],
                            ['title' => 'Production Full-Stack Application Capstone', 'type' => 'assignment', 'duration' => '90 min', 'description' => 'Submit complete full-stack web application repository and live demo URL.'],
                        ],
                    ],
                ],
            ],

            10 => [
                'slug' => 'cyber-security',
                'title' => 'Cyber Security',
                'category' => 'Cyber Security',
                'priority' => 10,
                'difficulty' => 'Beginner to Advanced',
                'duration' => '10 Weeks',
                'instructor' => 'Alex Turner',
                'thumbnail' => 'https://images.unsplash.com/photo-1550751827-4bd374c3f58b?auto=format&fit=crop&w=1200&q=80',
                'description' => 'Learn cybersecurity fundamentals, networking security, authentication, vulnerabilities, defensive security, security operations, and practical security concepts.',
                'full_description' => 'Comprehensive cybersecurity training covering networking defense, Linux security, cryptography, OWASP Top 10 web vulnerabilities, security operations (SOC), threat monitoring, and incident response.',
                'prerequisites' => ['Basic understanding of computers and operating systems'],
                'learning_objectives' => [
                    'Understand core cybersecurity principles: Confidentiality, Integrity, Availability',
                    'Analyze networking packets, protocols (TCP/IP, DNS, HTTPS), and firewalls',
                    'Implement symmetric and asymmetric cryptography, hashing, and PKI',
                    'Identify, test, and remediate OWASP Top 10 web vulnerabilities',
                    'Perform threat hunting, SIEM log analysis, and incident response',
                ],
                'skills_gained' => ['Cybersecurity Fundamentals', 'Networking Security', 'Linux Hardening', 'Cryptography & PKI', 'OWASP Top 10', 'Vulnerability Assessment', 'SOC Monitoring & SIEM', 'Incident Response', 'Ethical Defense'],
                'modules' => [
                    [
                        'title' => 'Module 1 — Cybersecurity, Networking & Linux Foundations',
                        'lessons' => [
                            ['title' => 'Cybersecurity Principles & The CIA Triad', 'type' => 'video', 'duration' => '25 min', 'video_url' => 'https://www.youtube.com/watch?v=inWWhr5tnEA', 'description' => 'Confidentiality, Integrity, Availability, threat actors, and attack vectors.'],
                            ['title' => 'Networking Protocols & Packet Analysis', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=IPvYjXCsTg8', 'description' => 'TCP/IP, UDP, DNS, DHCP, ARP, and Wireshark packet capture analysis.'],
                            ['title' => 'Linux Security, Permissions & System Hardening', 'type' => 'text', 'duration' => '30 min', 'description' => 'File permissions (chmod/chown), sudoers, SSH key auth, and disabling root.'],
                            ['title' => 'Firewalls, Network Segmentation & Port Scanning', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=4tBf1Zp7Q-k', 'description' => 'Nmap scanning, iptables, UFW rules, and DMZ network architecture.'],
                            ['title' => 'Cybersecurity Foundations Checkpoint', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Assess your networking, Linux, and security basics.'],
                        ],
                    ],
                    [
                        'title' => 'Module 2 — Identity, Access & Cryptography',
                        'lessons' => [
                            ['title' => 'Authentication & Authorization Mechanisms', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=27duWqXzQ04', 'description' => 'MFA, biometrics, OAuth 2.0, SAML, and least privilege access.'],
                            ['title' => 'Symmetric vs Asymmetric Cryptography', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=E5CnyYFZgLE', 'description' => 'AES, DES, RSA, Diffie-Hellman key exchange, and elliptic curve cryptography.'],
                            ['title' => 'Cryptographic Hashing & Digital Signatures', 'type' => 'text', 'duration' => '25 min', 'description' => 'SHA-256, collision resistance, HMAC, and non-repudiation.'],
                            ['title' => 'Public Key Infrastructure (PKI) & TLS/SSL', 'type' => 'text', 'duration' => '30 min', 'description' => 'Certificate authorities, CSR generation, SSL handshake, and HTTPS.'],
                            ['title' => 'Cryptography Lab Assignment', 'type' => 'assignment', 'duration' => '45 min', 'description' => 'Implement symmetric encryption and verify digital signatures in code.'],
                        ],
                    ],
                    [
                        'title' => 'Module 3 — Web Security & OWASP Concepts',
                        'lessons' => [
                            ['title' => 'OWASP Top 10 Web Vulnerabilities Overview', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=4B8F3g9uI_E', 'description' => 'Deep dive into the 10 most critical web application security risks.'],
                            ['title' => 'SQL Injection (SQLi) Mechanics & Prevention', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=ciNHn38EyRc', 'description' => 'In-band, blind, and time-based SQLi exploits and parameterized defenses.'],
                            ['title' => 'Cross-Site Scripting (XSS) & CSRF Attacks', 'type' => 'text', 'duration' => '30 min', 'description' => 'Reflected, stored, DOM XSS, Content Security Policy (CSP), and anti-CSRF tokens.'],
                            ['title' => 'Broken Access Control & Security Misconfigurations', 'type' => 'text', 'duration' => '25 min', 'description' => 'IDOR vulnerabilities, privilege escalation, and default credentials.'],
                            ['title' => 'Web Application Security Checkpoint', 'type' => 'quiz', 'duration' => '25 min', 'description' => 'Test your OWASP Top 10 vulnerability remediation knowledge.'],
                        ],
                    ],
                    [
                        'title' => 'Module 4 — Vulnerability Assessment & SOC Operations',
                        'lessons' => [
                            ['title' => 'Vulnerability Assessment Methodology', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=Xy2zF7_8J-E', 'description' => 'Vulnerability scanning with Nessus/OpenVAS, CVE databases, and CVSS scoring.'],
                            ['title' => 'Security Monitoring & SIEM Operations', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=4xY1wZ8kE3s', 'description' => 'Log ingestion, rule correlation, anomaly detection with Splunk and Elastic.'],
                            ['title' => 'Incident Response Lifecycle (NIST Framework)', 'type' => 'text', 'duration' => '30 min', 'description' => 'Preparation, detection, containment, eradication, recovery, and post-incident analysis.'],
                            ['title' => 'Threat Hunting & Indicator of Compromise (IoC)', 'type' => 'text', 'duration' => '25 min', 'description' => 'YARA rules, MITRE ATT&CK framework, and threat intelligence feeds.'],
                            ['title' => 'SOC Incident Response Lab', 'type' => 'assignment', 'duration' => '45 min', 'description' => 'Analyze real security log captures and write an incident triage report.'],
                        ],
                    ],
                    [
                        'title' => 'Module 5 — Ethical Security & Defensive Project',
                        'lessons' => [
                            ['title' => 'Ethical Security Concepts & Rules of Engagement', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=inWWhr5tnEA', 'description' => 'Scoping, legal authorizations, responsible disclosure, and ethics.'],
                            ['title' => 'Defense-in-Depth & Zero Trust Architecture', 'type' => 'text', 'duration' => '30 min', 'description' => 'Micro-segmentation, continuous verification, and secure network perimeters.'],
                            ['title' => 'Security Auditing & Compliance Standards', 'type' => 'text', 'duration' => '25 min', 'description' => 'ISO 27001, SOC 2, HIPAA, and PCI-DSS compliance requirements.'],
                            ['title' => 'Defensive Security Capstone Project', 'type' => 'assignment', 'duration' => '90 min', 'description' => 'Conduct a full defensive security audit and propose architecture hardening.'],
                        ],
                    ],
                ],
            ],

            11 => [
                'slug' => 'cloud-devops-engineering-mastery',
                'title' => 'Cloud & DevOps',
                'category' => 'Cloud & DevOps',
                'priority' => 11,
                'difficulty' => 'Beginner to Advanced',
                'duration' => '10 Weeks',
                'instructor' => 'Rajesh Kumar',
                'thumbnail' => 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1200&q=80',
                'description' => 'Learn cloud computing, Linux, Git, CI/CD, containers, deployment, monitoring, infrastructure concepts, and modern DevOps practices.',
                'full_description' => 'Master modern cloud architecture and DevOps workflows. Learn Linux administration, Git/GitHub, CI/CD automation, Docker containers, Kubernetes orchestration, AWS/Azure services, Terraform (IaC), and Prometheus/Grafana monitoring.',
                'prerequisites' => ['Basic command line and software development fundamentals'],
                'learning_objectives' => [
                    'Master Linux server administration and shell scripting',
                    'Automate build and deployment pipelines using GitHub Actions CI/CD',
                    'Containerize multi-tier applications using Docker and Docker Compose',
                    'Deploy and scale microservices using Kubernetes (Pods, Services, Ingress)',
                    'Architect cloud infrastructure on AWS and Azure with Terraform IaC',
                ],
                'skills_gained' => ['Linux & Bash', 'Git & GitHub Actions', 'CI/CD Pipelines', 'Docker', 'Kubernetes', 'AWS & Azure', 'Terraform (IaC)', 'Monitoring & Prometheus', 'Production Deployment'],
                'modules' => [
                    [
                        'title' => 'Module 1 — Linux Administration, Networking & Git',
                        'lessons' => [
                            ['title' => 'Linux Server Administration & Shell Commands', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=sWbGOq4stl8', 'description' => 'Processes (systemctl), package managers, cron jobs, and SSH config.'],
                            ['title' => 'Bash Scripting for DevOps Automation', 'type' => 'text', 'duration' => '30 min', 'description' => 'Variables, loops, conditions, exit codes, and automated server provisioning.'],
                            ['title' => 'Networking: DNS, Reverse Proxies (Nginx) & SSL', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=7V4F2E7h-sE', 'description' => 'Configuring Nginx reverse proxy, Certbot SSL, and load balancing.'],
                            ['title' => 'Git & GitHub Branching Strategies for DevOps', 'type' => 'text', 'duration' => '25 min', 'description' => 'Trunk-based development, semantic release tags, and repo hygiene.'],
                            ['title' => 'Linux & Git Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Assess your Linux system administration and Git skills.'],
                        ],
                    ],
                    [
                        'title' => 'Module 2 — Continuous Integration & Deployment (CI/CD)',
                        'lessons' => [
                            ['title' => 'CI/CD Concepts & Automated Pipelines', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=scEDHsr3APg', 'description' => 'Build automation, automated testing gates, and deployment strategies.'],
                            ['title' => 'Building GitHub Actions Workflows', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=R8_veQiYBjI', 'description' => 'Triggers, jobs, steps, action marketplace, and runner environments.'],
                            ['title' => 'Automated Testing, Linting & Security Scans in CI', 'type' => 'text', 'duration' => '30 min', 'description' => 'Running unit tests, SAST security checks, and code coverage.'],
                            ['title' => 'Continuous Deployment & Environment Secrets', 'type' => 'text', 'duration' => '25 min', 'description' => 'Deploying to staging/production and managing encrypted secrets.'],
                            ['title' => 'CI/CD Pipeline Lab Assignment', 'type' => 'assignment', 'duration' => '45 min', 'description' => 'Create a functional GitHub Actions CI/CD workflow that tests and builds an app.'],
                        ],
                    ],
                    [
                        'title' => 'Module 3 — Docker Containerization & Multi-Container Apps',
                        'lessons' => [
                            ['title' => 'Docker Fundamentals: Images, Containers & Registries', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=fqMOX6JJhGo', 'description' => 'Container isolation, Docker daemon, CLI commands, and Docker Hub.'],
                            ['title' => 'Writing Production-Ready Dockerfiles', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=8vXoMqWgbcQ', 'description' => 'Layer caching, multi-stage builds, non-root users, and minimal images.'],
                            ['title' => 'Docker Volumes, Networks & Multi-Container Compose', 'type' => 'text', 'duration' => '30 min', 'description' => 'Bridge networks, named volumes, and docker-compose.yml services.'],
                            ['title' => 'Containerization Checkpoint Quiz', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Test your Docker containerization concepts.'],
                        ],
                    ],
                    [
                        'title' => 'Module 4 — Kubernetes Orchestration & Cloud Infrastructure',
                        'lessons' => [
                            ['title' => 'Kubernetes Architecture & Core Objects', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=X48VuDVv0do', 'description' => 'Control plane, worker nodes, Kubelet, Pods, and ReplicaSets.'],
                            ['title' => 'Kubernetes Deployments, Services & Ingress', 'type' => 'video', 'duration' => '40 min', 'video_url' => 'https://www.youtube.com/watch?v=ASb29iL_gVo', 'description' => 'Rolling updates, ClusterIP, NodePort, LoadBalancer, and Ingress routing.'],
                            ['title' => 'Cloud Fundamentals: AWS & Azure Concepts', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=3hLmDS179YE', 'description' => 'AWS EC2, S3, RDS, IAM roles, and Azure equivalent services.'],
                            ['title' => 'Infrastructure as Code (IaC) with Terraform', 'type' => 'text', 'duration' => '30 min', 'description' => 'HCL syntax, providers, resources, state files, and terraform apply.'],
                            ['title' => 'Kubernetes & Cloud Assessment', 'type' => 'quiz', 'duration' => '25 min', 'description' => 'Validate your Kubernetes and cloud infrastructure knowledge.'],
                        ],
                    ],
                    [
                        'title' => 'Module 5 — Monitoring, Logging & DevOps Project',
                        'lessons' => [
                            ['title' => 'Monitoring with Prometheus & Grafana Dashboards', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=9TYX7HKtr34', 'description' => 'Scraping metrics, PromQL queries, alerting rules, and Grafana visual panels.'],
                            ['title' => 'Centralized Logging with ELK / FluentBit', 'type' => 'text', 'duration' => '30 min', 'description' => 'Log forwarding, parsing, index patterns, and incident investigation.'],
                            ['title' => 'Zero-Downtime Blue/Green & Canary Deployments', 'type' => 'text', 'duration' => '25 min', 'description' => 'Traffic splitting, automated rollbacks, and health checks.'],
                            ['title' => 'End-to-End Cloud & DevOps Capstone Project', 'type' => 'assignment', 'duration' => '90 min', 'description' => 'Deploy a containerized microservice with CI/CD pipeline and monitoring.'],
                        ],
                    ],
                ],
            ],

            12 => [
                'slug' => 'database-fundamentals',
                'title' => 'Database & SQL',
                'category' => 'Database',
                'priority' => 12,
                'difficulty' => 'Beginner to Advanced',
                'duration' => '8 Weeks',
                'instructor' => 'Vikram Malhotra',
                'thumbnail' => 'https://images.unsplash.com/photo-1544383835-bda2bc66a55d?auto=format&fit=crop&w=1200&q=80',
                'description' => 'Learn relational databases, SQL, database design, queries, indexing, transactions, PostgreSQL/MySQL concepts, and practical database development.',
                'full_description' => 'Master relational database design and SQL from basic SELECT queries to advanced multi-table joins, subqueries, indexing, constraints, ACID transactions, normalization, and PostgreSQL/MySQL administration.',
                'prerequisites' => ['No prior database experience required'],
                'learning_objectives' => [
                    'Design relational schemas using ER diagrams and normalization (1NF-3NF)',
                    'Write complex SQL queries with JOINs, GROUP BY, HAVING, and Subqueries',
                    'Implement database constraints, indexes, views, and stored procedures',
                    'Manage ACID transactions, locking, and data integrity',
                    'Optimize query execution plans in PostgreSQL and MySQL',
                ],
                'skills_gained' => ['Relational DB Design', 'SQL DDL & DML', 'Complex JOINs', 'Aggregation & Grouping', 'Subqueries & Views', 'Indexing & Query Plans', 'Transactions (ACID)', 'PostgreSQL & MySQL', 'Database Normalization'],
                'modules' => [
                    [
                        'title' => 'Module 1 — Database Fundamentals & Relational Theory',
                        'lessons' => [
                            ['title' => 'Introduction to Relational Databases & DBMS', 'type' => 'video', 'duration' => '25 min', 'video_url' => 'https://www.youtube.com/watch?v=wR0jg0eQsZA', 'description' => 'Data persistence, relational model, primary keys, and foreign keys.'],
                            ['title' => 'Entity-Relationship (ER) Modeling & Schema Design', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=QpdhBUYk7Kk', 'description' => 'Entities, relationships (1:1, 1:N, N:M), attributes, and ER diagrams.'],
                            ['title' => 'Database Normalization: 1NF, 2NF & 3NF', 'type' => 'text', 'duration' => '30 min', 'description' => 'Eliminating data redundancy, insertion/deletion anomalies, and functional dependencies.'],
                            ['title' => 'Relational Theory Checkpoint Quiz', 'type' => 'quiz', 'duration' => '15 min', 'description' => 'Assess your database modeling and normalization theory.'],
                        ],
                    ],
                    [
                        'title' => 'Module 2 — Core SQL DDL, DML & Constraints',
                        'lessons' => [
                            ['title' => 'Creating Tables & Data Types: CREATE, ALTER, DROP', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=HXV3zeRR3h4', 'description' => 'Table creation, varchar, integer, timestamp, UUID, and schema migrations.'],
                            ['title' => 'Data Manipulation: INSERT, UPDATE, DELETE', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=7S_tz1z_5bA', 'description' => 'Adding records, batch inserts, conditional updates, and safe deletes.'],
                            ['title' => 'Querying with SELECT, WHERE, ORDER BY & LIMIT', 'type' => 'text', 'duration' => '25 min', 'description' => 'Projection, filtering conditions, sorting order, and pagination.'],
                            ['title' => 'Database Constraints: PK, FK, UNIQUE, CHECK, NOT NULL', 'type' => 'text', 'duration' => '25 min', 'description' => 'Enforcing business rules and relational integrity at the schema level.'],
                            ['title' => 'SQL DDL & DML Lab Assignment', 'type' => 'assignment', 'duration' => '45 min', 'description' => 'Write SQL scripts to create a relational schema with constraints and seed data.'],
                        ],
                    ],
                    [
                        'title' => 'Module 3 — Advanced Querying: JOINs, GROUP BY & Subqueries',
                        'lessons' => [
                            ['title' => 'Mastering Table JOINs: INNER, LEFT, RIGHT, FULL', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=0r1SxFxGEvg', 'description' => 'Combining multiple tables with join predicates and handling null rows.'],
                            ['title' => 'Aggregation Functions & GROUP BY / HAVING', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=Ww71knvhQ-s', 'description' => 'COUNT, SUM, AVG, MIN, MAX with grouping and conditional filters.'],
                            ['title' => 'Subqueries, CTEs (WITH Clause) & Views', 'type' => 'text', 'duration' => '30 min', 'description' => 'Correlated subqueries, Common Table Expressions, and reusable virtual views.'],
                            ['title' => 'Advanced SQL Query Checkpoint', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Test your complex JOIN and subquery writing skills.'],
                        ],
                    ],
                    [
                        'title' => 'Module 4 — Indexes, Transactions & Optimization',
                        'lessons' => [
                            ['title' => 'Database Indexes: B-Trees & Performance', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=-qNSXK7sJeg', 'description' => 'Clustered vs non-clustered indexes, composite indexes, and index selectivity.'],
                            ['title' => 'Query Execution Plans: EXPLAIN ANALYZE', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=3_zVqQ1F_8k', 'description' => 'Seq scans, index scans, cost estimation, and query tuning.'],
                            ['title' => 'ACID Transactions & Concurrency Locking', 'type' => 'text', 'duration' => '30 min', 'description' => 'Commit, rollback, savepoints, dirty reads, and isolation levels.'],
                            ['title' => 'Index & Transaction Lab Assessment', 'type' => 'assignment', 'duration' => '45 min', 'description' => 'Optimize slow SQL queries using targeted indexes and explain plans.'],
                        ],
                    ],
                    [
                        'title' => 'Module 5 — Engine Specialization & Database Capstone Project',
                        'lessons' => [
                            ['title' => 'PostgreSQL vs MySQL Engines & Architecture', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=uD31_YyT6bI', 'description' => 'JSONB support, full-text search, storage engines, and replication.'],
                            ['title' => 'Database Backup, Restore & High Availability', 'type' => 'text', 'duration' => '25 min', 'description' => 'Dump utilities, point-in-time recovery, and primary-replica architecture.'],
                            ['title' => 'Production Relational Database Capstone Project', 'type' => 'assignment', 'duration' => '90 min', 'description' => 'Design an enterprise database schema, write queries, and benchmark performance.'],
                        ],
                    ],
                ],
            ],

            13 => [
                'slug' => 'python-fundamentals',
                'title' => 'Python Programming',
                'category' => 'Full Stack',
                'priority' => 13,
                'difficulty' => 'Beginner',
                'duration' => '8 Weeks',
                'instructor' => 'Aman Verma',
                'thumbnail' => 'https://images.unsplash.com/photo-1515879218367-8466d910aaa4?auto=format&fit=crop&w=1200&q=80',
                'description' => 'Build strong Python programming fundamentals including syntax, data structures, functions, object-oriented programming, modules, error handling, and practical projects.',
                'full_description' => 'Comprehensive Python programming course covering installation, variables, control flow, functions, lists, dictionaries, strings, file handling, OOP, decorators, generators, and testing.',
                'prerequisites' => ['No prior programming experience required', 'A computer capable of running Python 3.x'],
                'learning_objectives' => [
                    'Install Python, set up virtual environments, and use IDEs',
                    'Master control flow, conditionals, while and for loops',
                    'Work fluently with Python data structures (lists, tuples, sets, dictionaries)',
                    'Write reusable functions, closures, decorators, and generators',
                    'Apply object-oriented programming principles (encapsulation, inheritance, polymorphism)',
                    'Write unit tests and build complete Python automation projects',
                ],
                'skills_gained' => ['Python 3.x', 'Variables & Types', 'Control Structures', 'Data Structures', 'OOP in Python', 'Decorators & Generators', 'File I/O & Exceptions', 'Unit Testing', 'Python Projects'],
                'modules' => [
                    [
                        'title' => 'Module 1 — Getting Started & Language Basics',
                        'lessons' => [
                            ['title' => 'Python Installation, Virtual Environments & IDE Setup', 'type' => 'video', 'duration' => '20 min', 'video_url' => 'https://www.youtube.com/watch?v=kqtD5dpn9C8', 'description' => 'Installing Python 3.x, pip, virtualenv, and configuring VS Code.'],
                            ['title' => 'Variables, Data Types & Type Casting', 'type' => 'text', 'duration' => '25 min', 'description' => 'Integers, floats, strings, booleans, dynamic typing, and type conversions.'],
                            ['title' => 'Operators: Arithmetic, Comparison & Logical', 'type' => 'video', 'duration' => '25 min', 'video_url' => 'https://www.youtube.com/watch?v=v5MR5JnKcZI', 'description' => 'Mathematical precedence, comparison operators, and boolean logic.'],
                            ['title' => 'Control Flow: If, Elif, Else Conditions', 'type' => 'video', 'duration' => '25 min', 'video_url' => 'https://www.youtube.com/watch?v=AWek49wXGzI', 'description' => 'Conditional branching, nested conditions, and short-circuit evaluation.'],
                            ['title' => 'Loops: While Loops, For Loops & Range', 'type' => 'text', 'duration' => '30 min', 'description' => 'Iteration, range generator, break, continue, and pass statements.'],
                            ['title' => 'Python Language Basics Checkpoint', 'type' => 'quiz', 'duration' => '15 min', 'description' => 'Assess your understanding of basic Python syntax.'],
                        ],
                    ],
                    [
                        'title' => 'Module 2 — Data Structures & String Manipulation',
                        'lessons' => [
                            ['title' => 'Strings: Formatting, Slicing & Methods', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=k9TUPpGqYTo', 'description' => 'F-strings, string immutability, slicing syntax, and standard methods.'],
                            ['title' => 'Lists: Operations, Methods & Comprehensions', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=ohCDWZgNIU0', 'description' => 'Indexing, append, pop, sort, and list comprehensions.'],
                            ['title' => 'Tuples & Sets in Python', 'type' => 'text', 'duration' => '25 min', 'description' => 'Immutability, tuple packing/unpacking, and set mathematical operations.'],
                            ['title' => 'Dictionaries: Key-Value Mapping & Dict Comprehensions', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=daefaLgNkw0', 'description' => 'Dictionary methods, get, keys, values, items, and nested dictionaries.'],
                            ['title' => 'Data Structures Practice Lab', 'type' => 'assignment', 'duration' => '45 min', 'description' => 'Implement data processing scripts using lists, sets, and dictionaries.'],
                        ],
                    ],
                    [
                        'title' => 'Module 3 — Functions & Modular Programming',
                        'lessons' => [
                            ['title' => 'Defining Functions: Parameters, Returns & Scope', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=9Os0o3wzS_I', 'description' => 'Function definitions, docstrings, return values, and local vs global scope.'],
                            ['title' => 'Positional, Keyword, *args and **kwargs', 'type' => 'text', 'duration' => '25 min', 'description' => 'Flexible function signatures and variable-length arguments.'],
                            ['title' => 'File Handling: Reading & Writing Files', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=Uh2ebFW8OYM', 'description' => 'With context managers, reading lines, writing files, and JSON parsing.'],
                            ['title' => 'Exception Handling: Try, Except, Else, Finally', 'type' => 'text', 'duration' => '30 min', 'description' => 'Catching exceptions, raising custom errors, and defensive programming.'],
                            ['title' => 'Modules, Packages & Namespaces', 'type' => 'text', 'duration' => '25 min', 'description' => 'Importing modules, `__init__.py`, and `if __name__ == "__main__"` pattern.'],
                            ['title' => 'Functions & Error Handling Quiz', 'type' => 'quiz', 'duration' => '20 min', 'description' => 'Test your knowledge on functions, file I/O, and exceptions.'],
                        ],
                    ],
                    [
                        'title' => 'Module 4 — Object-Oriented Programming (OOP) & Advanced Idioms',
                        'lessons' => [
                            ['title' => 'Classes, Objects & The __init__ Constructor', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=ZDa-Z5JzLYM', 'description' => 'Class attributes, instance variables, methods, and self reference.'],
                            ['title' => 'Encapsulation & The @property Decorator', 'type' => 'text', 'duration' => '30 min', 'description' => 'Getters, setters, private variables, and data encapsulation.'],
                            ['title' => 'Inheritance, Polymorphism & super()', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=RSl87lqOXDE', 'description' => 'Single and multiple inheritance, method resolution order (MRO).'],
                            ['title' => 'Iterators & Generators with yield', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=bD05uGo_sVI', 'description' => 'Iterator protocol, next, iter, and memory-efficient generator functions.'],
                            ['title' => 'Function Decorators & Closures', 'type' => 'text', 'duration' => '30 min', 'description' => 'Higher-order functions, decorator syntax (`@decorator`), and wrappers.'],
                            ['title' => 'OOP & Advanced Python Checkpoint', 'type' => 'quiz', 'duration' => '25 min', 'description' => 'Validate your OOP, decorator, and generator concepts.'],
                        ],
                    ],
                    [
                        'title' => 'Module 5 — APIs, Testing & Python Application Capstone',
                        'lessons' => [
                            ['title' => 'Consuming Web APIs with the Requests Library', 'type' => 'video', 'duration' => '30 min', 'video_url' => 'https://www.youtube.com/watch?v=qUe3_0U2Pfc', 'description' => 'HTTP GET/POST requests, query parameters, headers, and status codes.'],
                            ['title' => 'Unit Testing Python Code with pytest & unittest', 'type' => 'video', 'duration' => '35 min', 'video_url' => 'https://www.youtube.com/watch?v=6tNS--WetLI', 'description' => 'Test assertions, test fixtures, test discovery, and automated execution.'],
                            ['title' => 'PEP 8 Code Quality, Linting & Type Hints', 'type' => 'text', 'duration' => '25 min', 'description' => 'Python style guide, Flake8/Black formatters, and static type hinting.'],
                            ['title' => 'Complete Python Application Capstone Project', 'type' => 'assignment', 'duration' => '90 min', 'description' => 'Build a modular, object-oriented Python application with tests and documentation.'],
                        ],
                    ],
                ],
            ],
        ];

        $topSlugs = array_column($priorityCourses, 'slug');

        // Apply Priority Courses, Sections, Lessons, Quizzes, and Assignments
        foreach ($priorityCourses as $pData) {
            $modules = $pData['modules'] ?? [];
            unset($pData['modules']);

            $pData['instructor_id'] = $tutor?->id;
            $pData['is_published'] = true;
            $pData['status'] = 'published';

            $course = Course::updateOrCreate(
                ['slug' => $pData['slug']],
                $pData
            );

            $createdSectionIds = [];
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
                        'description' => "Module " . ($mIndex + 1) . ": {$m['title']} for {$course->title}",
                        'sort_order' => $mIndex + 1,
                        'is_published' => true,
                    ]
                );
                $createdSectionIds[] = $section->id;

                $createdLessonIds = [];
                foreach ($lessons as $lIndex => $l) {
                    $lessonSlug = Str::slug($l['title']) . '-' . $section->id;
                    $lessonType = $l['type'] ?? 'text';
                    $lessonDuration = $l['duration'] ?? '25 min';
                    $lessonDesc = $l['description'] ?? $l['title'];
                    $videoUrl = $l['video_url'] ?? null;

                    $lesson = Lesson::updateOrCreate(
                        [
                            'course_id' => $course->id,
                            'section_id' => $section->id,
                            'slug' => $lessonSlug,
                        ],
                        [
                            'title' => $l['title'],
                            'description' => $lessonDesc,
                            'type' => $lessonType,
                            'duration' => $lessonDuration,
                            'sort_order' => $lIndex + 1,
                            'is_published' => true,
                            'metadata' => [
                                'content' => "## {$l['title']}\n\nWelcome to this comprehensive lesson on **{$l['title']}** as part of the *{$course->title}* program.\n\n### Key Concepts Covered:\n- Detailed examination of {$l['title']}\n- Industry best practices and real-world architectures\n- Step-by-step practical workflows\n- Hands-on exercises and key takeaways\n\nStudy the video lecture and supplementary resources below to master this topic.",
                                'video_url' => $videoUrl,
                                'reading_time' => '15 min',
                                'resources' => [
                                    ['title' => "Official {$l['title']} Reference Guide", 'url' => 'https://docs.masterintech.com'],
                                    ['title' => 'MasterInTech Curriculum Lecture Slides (PDF)', 'url' => 'https://assets.masterintech.com/slides'],
                                ],
                            ],
                        ]
                    );
                    $createdLessonIds[] = $lesson->id;

                    // Add Learning Resources
                    LessonResource::updateOrCreate(
                        [
                            'lesson_id' => $lesson->id,
                            'title' => "{$l['title']} - Study Guide & Notes",
                        ],
                        [
                            'file_url' => 'https://assets.masterintech.com/docs/study-guide.pdf',
                            'file_size' => '2.4 MB',
                            'description' => "Complete lecture notes, summary cheat sheet, and code examples for {$l['title']}.",
                            'sort_order' => 1,
                        ]
                    );

                    if ($lessonType === 'quiz') {
                        $quiz = Quiz::updateOrCreate(
                            ['lesson_id' => $lesson->id],
                            [
                                'title' => "{$l['title']} Checkpoint",
                                'description' => "Assess your knowledge of {$l['title']} and reinforce key concepts.",
                                'passing_score' => 70,
                                'time_limit' => 20,
                                'is_published' => true,
                            ]
                        );

                        if ($quiz->questions()->count() === 0) {
                            $q1 = QuizQuestion::create([
                                'quiz_id' => $quiz->id,
                                'question' => "What is the primary concept and best practice when applying {$course->title} principles in {$section->title}?",
                                'type' => 'multiple_choice',
                                'marks' => 5,
                                'sort_order' => 1,
                            ]);
                            QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Applying standardized, modular, and scalable industry methodologies', 'is_correct' => true]);
                            QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Bypassing validation and testing workflows', 'is_correct' => false]);
                            QuizOption::create(['question_id' => $q1->id, 'option_text' => 'Hardcoding arbitrary values without documentation', 'is_correct' => false]);

                            $q2 = QuizQuestion::create([
                                'quiz_id' => $quiz->id,
                                'question' => "Which of the following is an essential skill developed in {$l['title']}?",
                                'type' => 'multiple_choice',
                                'marks' => 5,
                                'sort_order' => 2,
                            ]);
                            QuizOption::create(['question_id' => $q2->id, 'option_text' => 'Understanding system architecture, implementation constraints, and continuous optimization', 'is_correct' => true]);
                            QuizOption::create(['question_id' => $q2->id, 'option_text' => 'Ignoring error logs and operational metrics', 'is_correct' => false]);
                        }
                    }

                    if ($lessonType === 'assignment') {
                        Assignment::updateOrCreate(
                            ['lesson_id' => $lesson->id],
                            [
                                'course_id' => $course->id,
                                'title' => "{$l['title']} Capstone Milestone",
                                'instructions' => "Complete the practical milestone for {$course->title} — {$l['title']}. Submit your code repository, architecture design document, and verification test results.",
                                'max_marks' => 100,
                                'due_date' => now()->addDays(30),
                                'is_published' => true,
                            ]
                        );
                    }
                }

                // Clean up orphaned lessons in this section
                Lesson::where('section_id', $section->id)
                    ->whereNotIn('id', $createdLessonIds)
                    ->delete();
            }

            // Clean up legacy/orphaned sections for this priority course
            Section::where('course_id', $course->id)
                ->whereNotIn('id', $createdSectionIds)
                ->delete();
        }

        // 2. Standardize Secondary Courses and Categories
        $categoryNormalizations = [
            'ARTIFICIAL INTELLIGENCE' => 'AI & ML',
            'Artificial Intelligence' => 'AI & ML',
            'Machine Learning' => 'AI & ML',
            'Generative AI' => 'AI & ML',
            'Deep Learning' => 'AI & ML',
            'Python with AI' => 'AI & ML',
            'CLOUD COMPUTING' => 'Cloud & DevOps',
            'Cloud Computing' => 'Cloud & DevOps',
            'DevOps' => 'Cloud & DevOps',
            'DATA ANALYST' => 'Data Science',
            'Data Analytics' => 'Data Science',
            'DATA ENGINEERING' => 'Data Science',
            'DATA SCIENCE' => 'Data Science',
            'DATABASE' => 'Database',
            'FULL STACK' => 'Full Stack',
            'Software Engineering' => 'Full Stack',
            'Healthcare & Life Sciences' => 'Healthcare',
            'Medical Coding' => 'Healthcare',
        ];

        foreach (Course::all() as $c) {
            // Keep instructor draft course untouched
            if ($c->slug === 'python-programming-hsGF') {
                continue;
            }

            // If it's not one of the top 13
            if (! in_array($c->slug, $topSlugs, true)) {
                $slug = $c->slug;
                $title = strtolower($c->title);
                $cat = $c->category;

                // Standardize category if in mapping
                if (isset($categoryNormalizations[$cat])) {
                    $cat = $categoryNormalizations[$cat];
                }

                // Fine-tune category based on title/slug
                if (str_contains($title, 'generative ai') || str_contains($title, 'llm') || str_contains($title, 'agent') || str_contains($title, 'deep learning') || str_contains($title, 'machine learning') || str_contains($title, 'artificial intelligence')) {
                    $cat = 'AI & ML';
                } elseif (str_contains($title, 'data analyst') || str_contains($title, 'data analysis') || str_contains($title, 'power bi') || str_contains($title, 'data science') || str_contains($title, 'data engineering')) {
                    $cat = 'Data Science';
                } elseif (str_contains($title, 'devops') || str_contains($title, 'kubernetes') || str_contains($title, 'sre') || str_contains($title, 'cloud') || str_contains($title, 'aws') || str_contains($title, 'azure') || str_contains($title, 'salesforce') || str_contains($title, 'boomi') || str_contains($title, 'servicenow')) {
                    $cat = 'Cloud & DevOps';
                } elseif (str_contains($title, 'database') || str_contains($title, 'sql') || str_contains($title, 'oracle') || str_contains($title, 'mongo')) {
                    $cat = 'Database';
                } elseif (str_contains($title, 'cyber') || str_contains($title, 'security') || str_contains($title, 'ethical hacking') || str_contains($title, 'penetration')) {
                    $cat = 'Cyber Security';
                } elseif (str_contains($title, 'sap')) {
                    $cat = 'SAP';
                } elseif (str_contains($title, 'medical') || str_contains($title, 'coding') || str_contains($title, 'healthcare') || str_contains($title, 'cpt') || str_contains($title, 'icd')) {
                    $cat = 'Healthcare';
                } elseif (str_contains($title, 'full stack') || str_contains($title, 'web development') || str_contains($title, 'react') || str_contains($title, 'laravel') || str_contains($title, 'microservices') || str_contains($title, 'api architecture')) {
                    $cat = 'Full Stack';
                }

                // Determine Tier 2 / Tier 3 priority for orderly display
                $tierPriority = 30;
                if ($cat === 'AI & ML') $tierPriority = 20;
                elseif ($cat === 'Data Science') $tierPriority = 21;
                elseif ($cat === 'Full Stack') $tierPriority = 22;
                elseif ($cat === 'Cloud & DevOps') $tierPriority = 23;
                elseif ($cat === 'Cyber Security') $tierPriority = 24;
                elseif ($cat === 'SAP') $tierPriority = 25;
                elseif ($cat === 'Healthcare') $tierPriority = 26;
                elseif ($cat === 'Database') $tierPriority = 27;
                else $tierPriority = 50;

                $c->update([
                    'category' => $cat,
                    'priority' => $tierPriority,
                ]);
            }
        }

        // Clear public catalog cache
        try {
            Cache::increment('public_catalog_version');
        } catch (\Throwable $e) {
            Cache::put('public_catalog_version', time(), 86400);
        }
    }
}
