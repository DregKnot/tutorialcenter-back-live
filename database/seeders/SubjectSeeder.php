<?php

namespace Database\Seeders;

use App\Models\Subject;
use Illuminate\Database\Seeder;

class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $subjects = [
            // Jamb Course Subjects
            /*
            |--------------------------------------------------------------------------
            | General (Compulsory)
            |--------------------------------------------------------------------------
            */

            [
                'name' => 'Use of English',
                'description' => 'Core language subject focusing on comprehension, grammar, essay writing, and oral English.',
                'banner' => 'subjects/use-of-english.png',
                'courses' => [1], // JAMB, WAEC, NECO, GCE
                'departments' => ['science', 'art', 'commercial', 'vocational'],
            ],
            [
                'name' => 'Mathematics',
                'description' => 'Study of arithmetic, algebra, geometry, trigonometry, and statistics.',
                'banner' => 'subjects/mathematics.png',
                'courses' => [1],
                'departments' => ['science', 'art', 'commercial', 'vocational'],
            ],
            [
                'name' => 'Computer Studies',
                'description' => 'Introduction to computers, ICT tools, and basic programming concepts.',
                'banner' => 'subjects/computer-studies.png',
                'courses' => [1],
                'departments' => ['science', 'art', 'commercial', 'vocational'],
            ],
            [
                'name' => 'Literature in English',
                'description' => 'Study of prose, poetry, and drama with literary analysis.',
                'banner' => 'subjects/literature.png',
                'courses' => [1],
                'departments' => ['science', 'art', 'commercial', 'vocational'],
            ],
            /*
            |--------------------------------------------------------------------------
            | Science Department
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'Physics',
                'description' => 'Study of matter, energy, motion, waves, electricity, and magnetism.',
                'banner' => 'subjects/physics.png',
                'courses' => [1],
                'departments' => ['science'],
            ],
            [
                'name' => 'Chemistry',
                'description' => 'Focuses on chemical reactions, equations, organic and inorganic chemistry.',
                'banner' => 'subjects/chemistry.png',
                'courses' => [1],
                'departments' => ['science'],
            ],
            [
                'name' => 'Biology',
                'description' => 'Study of living organisms including plants, animals, and human systems.',
                'banner' => 'subjects/biology.png',
                'courses' => [1],
                'departments' => ['science'],
            ],
            [
                'name' => 'Agricultural Science',
                'description' => 'Covers crop production, animal husbandry, and agricultural practices.',
                'banner' => 'subjects/agricultural-science.png',
                'courses' => [1],
                'departments' => ['science'],
            ],
            [
                'name' => 'Geography',
                'description' => 'Study of the earth, environment, climate, and human activities.',
                'banner' => 'subjects/geography.png',
                'courses' => [1],
                'departments' => ['science'],
            ],
            [
                'name' => 'Further Mathematics',
                'description' => 'Advanced mathematics including calculus, vectors, and advanced algebra.',
                'banner' => 'subjects/further-mathematics.png',
                'courses' => [1],
                'departments' => ['science'],
            ],
            [
                'name' => 'Physical Education',
                'description' => 'Study of physical fitness, sports, health, and human movement.',
                'banner' => 'subjects/physical-education.png',
                'courses' => [1],
                'departments' => ['science'],
            ],
            [
                'name' => 'Technical Drawing',
                'description' => 'Engineering drawing, projections, and technical design principles.',
                'banner' => 'subjects/technical-drawing.png',
                'courses' => [1],
                'departments' => ['science'],
            ],
            [
                'name' => 'Home Economics',
                'description' => 'Covers nutrition, home management, and family living.',
                'banner' => 'subjects/home-economics.png',
                'courses' => [1],
                'departments' => ['science'],
            ],

            /*
            |--------------------------------------------------------------------------
            | Art & Humanities Department
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'Government',
                'description' => 'Study of political systems, governance, and constitutions.',
                'banner' => 'subjects/government.png',
                'courses' => [1],
                'departments' => ['art'],
            ],
            [
                'name' => 'History',
                'description' => 'Study of past events, civilizations, and historical development.',
                'banner' => 'subjects/history.png',
                'courses' => [1],
                'departments' => ['art'],
            ],
            [
                'name' => 'Christian Religious Knowledge (CRK)',
                'description' => 'Study of Christian beliefs, Bible knowledge, and moral values.',
                'banner' => 'subjects/crk.png',
                'courses' => [1],
                'departments' => ['art'],
            ],
            [
                'name' => 'Islamic Religious Knowledge (IRK)',
                'description' => 'Study of Islamic teachings, Quran, and moral principles.',
                'banner' => 'subjects/irk.png',
                'courses' => [1],
                'departments' => ['art'],
            ],
            [
                'name' => 'Fine Arts',
                'description' => 'Visual arts including drawing, painting, and sculpture.',
                'banner' => 'subjects/fine-arts.png',
                'courses' => [1],
                'departments' => ['art'],
            ],
            [
                'name' => 'Music',
                'description' => 'Music theory, appreciation, and performance.',
                'banner' => 'subjects/music.png',
                'courses' => [1],
                'departments' => ['art'],
            ],
            [
                'name' => 'French',
                'description' => 'Foreign language study focusing on grammar and communication.',
                'banner' => 'subjects/french.png',
                'courses' => [1],
                'departments' => ['art'],
            ],
            [
                'name' => 'Arabic',
                'description' => 'Arabic language, grammar, and comprehension.',
                'banner' => 'subjects/arabic.png',
                'courses' => [1],
                'departments' => ['art'],
            ],
            [
                'name' => 'Hausa',
                'description' => 'Hausa language, literature, and culture.',
                'banner' => 'subjects/hausa.png',
                'courses' => [1],
                'departments' => ['art'],
            ],
            [
                'name' => 'Igbo',
                'description' => 'Igbo language, literature, and culture.',
                'banner' => 'subjects/igbo.png',
                'courses' => [1],
                'departments' => ['art'],
            ],
            [
                'name' => 'Yoruba',
                'description' => 'Yoruba language, literature, and culture.',
                'banner' => 'subjects/yoruba.png',
                'courses' => [1],
                'departments' => ['art'],
            ],

            /*
            |--------------------------------------------------------------------------
            | Commercial Department
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'Economics',
                'description' => 'Study of production, distribution, and economic systems.',
                'banner' => 'subjects/economics.png',
                'courses' => [1],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Commerce',
                'description' => 'Study of trade, business activities, and commercial practices.',
                'banner' => 'subjects/commerce.png',
                'courses' => [1],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Principles of Accounts',
                'description' => 'Introduction to bookkeeping and accounting principles.',
                'banner' => 'subjects/principles-of-accounts.png',
                'courses' => [1],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Financial Accounting',
                'description' => 'Advanced accounting and financial statement analysis.',
                'banner' => 'subjects/financial-accounting.png',
                'courses' => [1],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Civic Education',
                'description' => 'Citizenship education, civic duties, and national values.',
                'banner' => 'subjects/civic-education.png',
                'courses' => [1],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Data Processing',
                'description' => 'Study of data handling and information processing.',
                'banner' => 'subjects/data-processing.png',
                'courses' => [1],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Marketing',
                'description' => 'Market research, advertising, and consumer behavior.',
                'banner' => 'subjects/marketing.png',
                'courses' => [1],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Insurance',
                'description' => 'Risk management and insurance principles.',
                'banner' => 'subjects/insurance.png',
                'courses' => [1],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Office Practice',
                'description' => 'Office procedures, administration, and clerical operations.',
                'banner' => 'subjects/office-practice.png',
                'courses' => [1],
                'departments' => ['commercial'],
            ],

            /*
             * WAEC Course Subjects
             */
            /*
            |--------------------------------------------------------------------------
            | Compulsory Core Subjects
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'English Language',
                'description' => 'Core language subject covering grammar, comprehension, essay writing, and oral English.',
                'banner' => 'subjects/english-language.png',
                'courses' => [2],
                'departments' => ['science', 'art', 'commercial', 'vocational'],
            ],
            [
                'name' => 'General Mathematics',
                'description' => 'Covers arithmetic, algebra, geometry, trigonometry, and basic statistics.',
                'banner' => 'subjects/general-mathematics.png',
                'courses' => [2],
                'departments' => ['science', 'art', 'commercial', 'vocational'],
            ],
            [
                'name' => 'Citizenship & Heritage Studies',
                'description' => 'Focuses on civic responsibility, national values, and Nigerian heritage.',
                'banner' => 'subjects/citizenship-heritage.png',
                'courses' => [2],
                'departments' => ['science', 'art', 'commercial', 'vocational'],
            ],

            /*
            |--------------------------------------------------------------------------
            | Science Department
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'Biology',
                'description' => 'Study of living organisms, plants, animals, and human systems.',
                'banner' => 'subjects/biology.png',
                'courses' => [2],
                'departments' => ['science'],
            ],
            [
                'name' => 'Chemistry',
                'description' => 'Study of chemical reactions, equations, organic and inorganic chemistry.',
                'banner' => 'subjects/chemistry.png',
                'courses' => [2],
                'departments' => ['science'],
            ],
            [
                'name' => 'Physics',
                'description' => 'Study of matter, energy, motion, electricity, and magnetism.',
                'banner' => 'subjects/physics.png',
                'courses' => [2],
                'departments' => ['science'],
            ],
            [
                'name' => 'Geography',
                'description' => 'Study of the earth, climate, environment, and human activities.',
                'banner' => 'subjects/geography.png',
                'courses' => [2],
                'departments' => ['science'],
            ],
            [
                'name' => 'Further Mathematics',
                'description' => 'Advanced mathematics including calculus, vectors, and advanced algebra.',
                'banner' => 'subjects/further-mathematics.png',
                'courses' => [2],
                'departments' => ['science'],
            ],
            // [
            //     'name' => 'Agricultural Science',
            //     'description' => 'Covers crop production, animal husbandry, and farm management.',
            //     'banner' => 'subjects/agricultural-science.png',
            //     'courses' => [2],
            //     'departments' => ['science'],
            // ],
            [
                'name' => 'Technical Drawing',
                'description' => 'Engineering and architectural drawing, projections, and design.',
                'banner' => 'subjects/technical-drawing.png',
                'courses' => [2],
                'departments' => ['science'],
            ],
            [
                'name' => 'Physical & Health Education',
                'description' => 'Covers physical fitness, sports science, and health education.',
                'banner' => 'subjects/physical-health-education.png',
                'courses' => [2],
                'departments' => ['science'],
            ],
            [
                'name' => 'Foods & Nutrition',
                'description' => 'Study of food nutrients, meal planning, and healthy living.',
                'banner' => 'subjects/foods-nutrition.png',
                'courses' => [2],
                'departments' => ['science'],
            ],

            /*
            |--------------------------------------------------------------------------
            | Humanities Department
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'Literature-in-English',
                'description' => 'Study of prose, poetry, and drama with literary analysis.',
                'banner' => 'subjects/literature-english.png',
                'courses' => [2],
                'departments' => ['art'],
            ],
            [
                'name' => 'Government',
                'description' => 'Study of political systems, governance, and constitutions.',
                'banner' => 'subjects/government.png',
                'courses' => [2],
                'departments' => ['art'],
            ],
            [
                'name' => 'Christian Religious Studies (CRS)',
                'description' => 'Study of Christian beliefs, Bible knowledge, and moral teachings.',
                'banner' => 'subjects/crs.png',
                'courses' => [2],
                'departments' => ['art'],
            ],
            [
                'name' => 'Islamic Religious Studies (IRS)',
                'description' => 'Study of Islamic teachings, Quran, and moral principles.',
                'banner' => 'subjects/irs.png',
                'courses' => [2],
                'departments' => ['art'],
            ],
            [
                'name' => 'History (Nigerian History)',
                'description' => 'Study of Nigerian history, culture, and historical development.',
                'banner' => 'subjects/nigerian-history.png',
                'courses' => [2],
                'departments' => ['art'],
            ],
            [
                'name' => 'Music / Visual Art',
                'description' => 'Creative arts including music theory, performance, and visual arts.',
                'banner' => 'subjects/music-visual-art.png',
                'courses' => [2],
                'departments' => ['art'],
            ],
            [
                'name' => 'Indigenous Languages',
                'description' => 'Study of Nigerian languages such as Hausa, Igbo, Yoruba, Edo, Efik, and Ibibio.',
                'banner' => 'subjects/indigenous-languages.png',
                'courses' => [2],
                'departments' => ['art'],
            ],
            [
                'name' => 'Arabic / French',
                'description' => 'Foreign language studies focusing on communication and grammar.',
                'banner' => 'subjects/arabic-french.png',
                'courses' => [2],
                'departments' => ['art'],
            ],

            /*
            |--------------------------------------------------------------------------
            | Business Department
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'Economics',
                'description' => 'Study of production, distribution, and economic systems.',
                'banner' => 'subjects/economics.png',
                'courses' => [2],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Commerce',
                'description' => 'Study of trade, business activities, and commercial practices.',
                'banner' => 'subjects/commerce.png',
                'courses' => [2],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Financial Accounting',
                'description' => 'Study of bookkeeping, accounting principles, and financial records.',
                'banner' => 'subjects/financial-accounting.png',
                'courses' => [2],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Marketing',
                'description' => 'Study of marketing strategies, advertising, and consumer behavior.',
                'banner' => 'subjects/marketing.png',
                'courses' => [2],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Office Practice',
                'description' => 'Office administration, clerical duties, and workplace procedures.',
                'banner' => 'subjects/office-practice.png',
                'courses' => [2],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Bookkeeping',
                'description' => 'Recording of financial transactions and basic accounting.',
                'banner' => 'subjects/bookkeeping.png',
                'courses' => [2],
                'departments' => ['commercial'],
            ],

            /*
            |--------------------------------------------------------------------------
            | Vocational / Trade Subjects
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'Data Processing',
                'description' => 'Basic computing, data handling, and information processing.',
                'banner' => 'subjects/data-processing.png',
                'courses' => [2],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'Animal Husbandry',
                'description' => 'Practical livestock farming and animal care.',
                'banner' => 'subjects/animal-husbandry.png',
                'courses' => [2],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'Catering Craft Practice',
                'description' => 'Food preparation, catering services, and hospitality skills.',
                'banner' => 'subjects/catering-craft.png',
                'courses' => [2],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'Garment Making / Fashion Design',
                'description' => 'Clothing design, sewing, and fashion construction.',
                'banner' => 'subjects/fashion-design.png',
                'courses' => [2],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'GSM Phone Maintenance & Repair',
                'description' => 'Mobile phone repair, diagnostics, and maintenance.',
                'banner' => 'subjects/gsm-repair.png',
                'courses' => [2],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'Photography / Computer Hardware Repairs',
                'description' => 'Digital photography and basic computer hardware maintenance.',
                'banner' => 'subjects/photography-hardware.png',
                'courses' => [2],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'Beauty Therapy & Cosmetology',
                'description' => 'Skincare, hairdressing, and beauty treatments.',
                'banner' => 'subjects/beauty-therapy.png',
                'courses' => [2],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'Solar PV Installation & Maintenance',
                'description' => 'Installation and maintenance of solar power systems.',
                'banner' => 'subjects/solar-pv.png',
                'courses' => [2],
                'departments' => ['vocational'],
            ],

            /*
             * NECO Course Subjects
             */
            /*
            |--------------------------------------------------------------------------
            | Compulsory Core Subjects
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'English Language',
                'description' => 'Core language subject covering grammar, comprehension, essay writing, and oral English.',
                'banner' => 'subjects/english-language.png',
                'courses' => [3],
                'departments' => ['science', 'art', 'commercial', 'vocational'],
            ],
            [
                'name' => 'General Mathematics',
                'description' => 'Covers arithmetic, algebra, geometry, trigonometry, and basic statistics.',
                'banner' => 'subjects/general-mathematics.png',
                'courses' => [3],
                'departments' => ['science', 'art', 'commercial', 'vocational'],
            ],
            [
                'name' => 'Citizenship & Heritage Studies',
                'description' => 'Focuses on civic responsibility, national values, and Nigerian heritage.',
                'banner' => 'subjects/citizenship-heritage.png',
                'courses' => [3],
                'departments' => ['science', 'art', 'commercial', 'vocational'],
            ],

            /*
            |--------------------------------------------------------------------------
            | Science Department
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'Biology',
                'description' => 'Study of living organisms, plants, animals, and human systems.',
                'banner' => 'subjects/biology.png',
                'courses' => [3],
                'departments' => ['science'],
            ],
            [
                'name' => 'Chemistry',
                'description' => 'Study of chemical reactions, equations, organic and inorganic chemistry.',
                'banner' => 'subjects/chemistry.png',
                'courses' => [3],
                'departments' => ['science'],
            ],
            [
                'name' => 'Physics',
                'description' => 'Study of matter, energy, motion, electricity, and magnetism.',
                'banner' => 'subjects/physics.png',
                'courses' => [3],
                'departments' => ['science'],
            ],
            [
                'name' => 'Geography',
                'description' => 'Study of the earth, climate, environment, and human activities.',
                'banner' => 'subjects/geography.png',
                'courses' => [3],
                'departments' => ['science'],
            ],
            [
                'name' => 'Further Mathematics',
                'description' => 'Advanced mathematics including calculus, vectors, and advanced algebra.',
                'banner' => 'subjects/further-mathematics.png',
                'courses' => [3],
                'departments' => ['science'],
            ],
            [
                'name' => 'Agricultural Science',
                'description' => 'Covers crop production, animal husbandry, and farm management.',
                'banner' => 'subjects/agricultural-science.png',
                'courses' => [2],
                'departments' => ['science'],
            ],
            [
                'name' => 'Technical Drawing',
                'description' => 'Engineering and architectural drawing, projections, and design.',
                'banner' => 'subjects/technical-drawing.png',
                'courses' => [3],
                'departments' => ['science'],
            ],
            [
                'name' => 'Physical & Health Education',
                'description' => 'Covers physical fitness, sports science, and health education.',
                'banner' => 'subjects/physical-health-education.png',
                'courses' => [3],
                'departments' => ['science'],
            ],
            [
                'name' => 'Foods & Nutrition',
                'description' => 'Study of food nutrients, meal planning, and healthy living.',
                'banner' => 'subjects/foods-nutrition.png',
                'courses' => [3],
                'departments' => ['science'],
            ],

            /*
            |--------------------------------------------------------------------------
            | Humanities Department
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'Literature-in-English',
                'description' => 'Study of prose, poetry, and drama with literary analysis.',
                'banner' => 'subjects/literature-english.png',
                'courses' => [3],
                'departments' => ['art'],
            ],
            [
                'name' => 'Government',
                'description' => 'Study of political systems, governance, and constitutions.',
                'banner' => 'subjects/government.png',
                'courses' => [3],
                'departments' => ['art'],
            ],
            [
                'name' => 'Christian Religious Studies (CRS)',
                'description' => 'Study of Christian beliefs, Bible knowledge, and moral teachings.',
                'banner' => 'subjects/crs.png',
                'courses' => [3],
                'departments' => ['art'],
            ],
            [
                'name' => 'Islamic Religious Studies (IRS)',
                'description' => 'Study of Islamic teachings, Quran, and moral principles.',
                'banner' => 'subjects/irs.png',
                'courses' => [3],
                'departments' => ['art'],
            ],
            [
                'name' => 'History (Nigerian History)',
                'description' => 'Study of Nigerian history, culture, and historical development.',
                'banner' => 'subjects/nigerian-history.png',
                'courses' => [3],
                'departments' => ['art'],
            ],
            [
                'name' => 'Music / Visual Art',
                'description' => 'Creative arts including music theory, performance, and visual arts.',
                'banner' => 'subjects/music-visual-art.png',
                'courses' => [3],
                'departments' => ['art'],
            ],
            [
                'name' => 'Indigenous Languages',
                'description' => 'Study of Nigerian languages such as Hausa, Igbo, Yoruba, Edo, Efik, and Ibibio.',
                'banner' => 'subjects/indigenous-languages.png',
                'courses' => [3],
                'departments' => ['art'],
            ],
            [
                'name' => 'Arabic / French',
                'description' => 'Foreign language studies focusing on communication and grammar.',
                'banner' => 'subjects/arabic-french.png',
                'courses' => [3],
                'departments' => ['art'],
            ],

            /*
            |--------------------------------------------------------------------------
            | Business Department
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'Economics',
                'description' => 'Study of production, distribution, and economic systems.',
                'banner' => 'subjects/economics.png',
                'courses' => [3],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Commerce',
                'description' => 'Study of trade, business activities, and commercial practices.',
                'banner' => 'subjects/commerce.png',
                'courses' => [3],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Financial Accounting',
                'description' => 'Study of bookkeeping, accounting principles, and financial records.',
                'banner' => 'subjects/financial-accounting.png',
                'courses' => [3],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Marketing',
                'description' => 'Study of marketing strategies, advertising, and consumer behavior.',
                'banner' => 'subjects/marketing.png',
                'courses' => [3],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Office Practice',
                'description' => 'Office administration, clerical duties, and workplace procedures.',
                'banner' => 'subjects/office-practice.png',
                'courses' => [3],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Bookkeeping',
                'description' => 'Recording of financial transactions and basic accounting.',
                'banner' => 'subjects/bookkeeping.png',
                'courses' => [3],
                'departments' => ['commercial'],
            ],

            /*
            |--------------------------------------------------------------------------
            | Vocational / Trade Subjects
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'Data Processing',
                'description' => 'Basic computing, data handling, and information processing.',
                'banner' => 'subjects/data-processing.png',
                'courses' => [3],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'Animal Husbandry',
                'description' => 'Practical livestock farming and animal care.',
                'banner' => 'subjects/animal-husbandry.png',
                'courses' => [3],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'Catering Craft Practice',
                'description' => 'Food preparation, catering services, and hospitality skills.',
                'banner' => 'subjects/catering-craft.png',
                'courses' => [3],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'Garment Making / Fashion Design',
                'description' => 'Clothing design, sewing, and fashion construction.',
                'banner' => 'subjects/fashion-design.png',
                'courses' => [3],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'GSM Phone Maintenance & Repair',
                'description' => 'Mobile phone repair, diagnostics, and maintenance.',
                'banner' => 'subjects/gsm-repair.png',
                'courses' => [3],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'Photography / Computer Hardware Repairs',
                'description' => 'Digital photography and basic computer hardware maintenance.',
                'banner' => 'subjects/photography-hardware.png',
                'courses' => [3],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'Beauty Therapy & Cosmetology',
                'description' => 'Skincare, hairdressing, and beauty treatments.',
                'banner' => 'subjects/beauty-therapy.png',
                'courses' => [3],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'Solar PV Installation & Maintenance',
                'description' => 'Installation and maintenance of solar power systems.',
                'banner' => 'subjects/solar-pv.png',
                'courses' => [3],
                'departments' => ['vocational'],
            ],
            /*
             * GCE Course Subjects
             */
            /*
            |--------------------------------------------------------------------------
            | Compulsory Core Subjects
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'English Language',
                'description' => 'Core language subject covering grammar, comprehension, essay writing, and oral English.',
                'banner' => 'subjects/english-language.png',
                'courses' => [4],
                'departments' => ['science', 'art', 'commercial', 'vocational'],
            ],
            [
                'name' => 'General Mathematics',
                'description' => 'Covers arithmetic, algebra, geometry, trigonometry, and basic statistics.',
                'banner' => 'subjects/general-mathematics.png',
                'courses' => [4],
                'departments' => ['science', 'art', 'commercial', 'vocational'],
            ],
            [
                'name' => 'Citizenship & Heritage Studies',
                'description' => 'Focuses on civic responsibility, national values, and Nigerian heritage.',
                'banner' => 'subjects/citizenship-heritage.png',
                'courses' => [4],
                'departments' => ['science', 'art', 'commercial', 'vocational'],
            ],

            /*
            |--------------------------------------------------------------------------
            | Science Department
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'Biology',
                'description' => 'Study of living organisms, plants, animals, and human systems.',
                'banner' => 'subjects/biology.png',
                'courses' => [4],
                'departments' => ['science'],
            ],
            [
                'name' => 'Chemistry',
                'description' => 'Study of chemical reactions, equations, organic and inorganic chemistry.',
                'banner' => 'subjects/chemistry.png',
                'courses' => [4],
                'departments' => ['science'],
            ],
            [
                'name' => 'Physics',
                'description' => 'Study of matter, energy, motion, electricity, and magnetism.',
                'banner' => 'subjects/physics.png',
                'courses' => [4],
                'departments' => ['science'],
            ],
            [
                'name' => 'Geography',
                'description' => 'Study of the earth, climate, environment, and human activities.',
                'banner' => 'subjects/geography.png',
                'courses' => [4],
                'departments' => ['science'],
            ],
            [
                'name' => 'Further Mathematics',
                'description' => 'Advanced mathematics including calculus, vectors, and advanced algebra.',
                'banner' => 'subjects/further-mathematics.png',
                'courses' => [4],
                'departments' => ['science'],
            ],
            [
                'name' => 'Agricultural Science',
                'description' => 'Covers crop production, animal husbandry, and farm management.',
                'banner' => 'subjects/agricultural-science.png',
                'courses' => [4],
                'departments' => ['science'],
            ],
            [
                'name' => 'Technical Drawing',
                'description' => 'Engineering and architectural drawing, projections, and design.',
                'banner' => 'subjects/technical-drawing.png',
                'courses' => [4],
                'departments' => ['science'],
            ],
            [
                'name' => 'Physical & Health Education',
                'description' => 'Covers physical fitness, sports science, and health education.',
                'banner' => 'subjects/physical-health-education.png',
                'courses' => [4],
                'departments' => ['science'],
            ],
            [
                'name' => 'Foods & Nutrition',
                'description' => 'Study of food nutrients, meal planning, and healthy living.',
                'banner' => 'subjects/foods-nutrition.png',
                'courses' => [4],
                'departments' => ['science'],
            ],

            /*
            |--------------------------------------------------------------------------
            | Humanities Department
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'Literature-in-English',
                'description' => 'Study of prose, poetry, and drama with literary analysis.',
                'banner' => 'subjects/literature-english.png',
                'courses' => [4],
                'departments' => ['art'],
            ],
            [
                'name' => 'Government',
                'description' => 'Study of political systems, governance, and constitutions.',
                'banner' => 'subjects/government.png',
                'courses' => [4],
                'departments' => ['art'],
            ],
            [
                'name' => 'Christian Religious Studies (CRS)',
                'description' => 'Study of Christian beliefs, Bible knowledge, and moral teachings.',
                'banner' => 'subjects/crs.png',
                'courses' => [4],
                'departments' => ['art'],
            ],
            [
                'name' => 'Islamic Religious Studies (IRS)',
                'description' => 'Study of Islamic teachings, Quran, and moral principles.',
                'banner' => 'subjects/irs.png',
                'courses' => [4],
                'departments' => ['art'],
            ],
            [
                'name' => 'History (Nigerian History)',
                'description' => 'Study of Nigerian history, culture, and historical development.',
                'banner' => 'subjects/nigerian-history.png',
                'courses' => [4],
                'departments' => ['art'],
            ],
            [
                'name' => 'Music / Visual Art',
                'description' => 'Creative arts including music theory, performance, and visual arts.',
                'banner' => 'subjects/music-visual-art.png',
                'courses' => [4],
                'departments' => ['art'],
            ],
            [
                'name' => 'Indigenous Languages',
                'description' => 'Study of Nigerian languages such as Hausa, Igbo, Yoruba, Edo, Efik, and Ibibio.',
                'banner' => 'subjects/indigenous-languages.png',
                'courses' => [4],
                'departments' => ['art'],
            ],
            [
                'name' => 'Arabic / French',
                'description' => 'Foreign language studies focusing on communication and grammar.',
                'banner' => 'subjects/arabic-french.png',
                'courses' => [4],
                'departments' => ['art'],
            ],

            /*
            |--------------------------------------------------------------------------
            | Business Department
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'Economics',
                'description' => 'Study of production, distribution, and economic systems.',
                'banner' => 'subjects/economics.png',
                'courses' => [4],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Commerce',
                'description' => 'Study of trade, business activities, and commercial practices.',
                'banner' => 'subjects/commerce.png',
                'courses' => [4],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Financial Accounting',
                'description' => 'Study of bookkeeping, accounting principles, and financial records.',
                'banner' => 'subjects/financial-accounting.png',
                'courses' => [4],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Marketing',
                'description' => 'Study of marketing strategies, advertising, and consumer behavior.',
                'banner' => 'subjects/marketing.png',
                'courses' => [4],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Office Practice',
                'description' => 'Office administration, clerical duties, and workplace procedures.',
                'banner' => 'subjects/office-practice.png',
                'courses' => [4],
                'departments' => ['commercial'],
            ],
            [
                'name' => 'Bookkeeping',
                'description' => 'Recording of financial transactions and basic accounting.',
                'banner' => 'subjects/bookkeeping.png',
                'courses' => [4],
                'departments' => ['commercial'],
            ],

            /*
            |--------------------------------------------------------------------------
            | Vocational / Trade Subjects
            |--------------------------------------------------------------------------
            */
            [
                'name' => 'Data Processing',
                'description' => 'Basic computing, data handling, and information processing.',
                'banner' => 'subjects/data-processing.png',
                'courses' => [4],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'Animal Husbandry',
                'description' => 'Practical livestock farming and animal care.',
                'banner' => 'subjects/animal-husbandry.png',
                'courses' => [4],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'Catering Craft Practice',
                'description' => 'Food preparation, catering services, and hospitality skills.',
                'banner' => 'subjects/catering-craft.png',
                'courses' => [4],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'Garment Making / Fashion Design',
                'description' => 'Clothing design, sewing, and fashion construction.',
                'banner' => 'subjects/fashion-design.png',
                'courses' => [4],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'GSM Phone Maintenance & Repair',
                'description' => 'Mobile phone repair, diagnostics, and maintenance.',
                'banner' => 'subjects/gsm-repair.png',
                'courses' => [4],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'Photography / Computer Hardware Repairs',
                'description' => 'Digital photography and basic computer hardware maintenance.',
                'banner' => 'subjects/photography-hardware.png',
                'courses' => [4],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'Beauty Therapy & Cosmetology',
                'description' => 'Skincare, hairdressing, and beauty treatments.',
                'banner' => 'subjects/beauty-therapy.png',
                'courses' => [4],
                'departments' => ['vocational'],
            ],
            [
                'name' => 'Solar PV Installation & Maintenance',
                'description' => 'Installation and maintenance of solar power systems.',
                'banner' => 'subjects/solar-pv.png',
                'courses' => [4],
                'departments' => ['vocational'],
            ],
        ];

        $subjects = collect($subjects)
            ->groupBy('name')
            ->map(function ($definitions) {
                $subject = $definitions->last();
                $subject['courses'] = $definitions
                    ->flatMap(fn ($definition) => $definition['courses'])
                    ->unique()
                    ->values()
                    ->all();
                $subject['departments'] = $definitions
                    ->flatMap(fn ($definition) => $definition['departments'])
                    ->unique()
                    ->values()
                    ->all();

                return $subject;
            });

        foreach ($subjects as $subject) {
            $record = Subject::withTrashed()->updateOrCreate(
                ['name' => $subject['name']],
                [
                    'description' => $subject['description'],
                    'banner' => $subject['banner'],
                    'departments' => $subject['departments'],
                    'status' => 'active',
                    'deleted_at' => null,
                ]
            );

            $record->courses()->sync($subject['courses']);
        }
    }
}
