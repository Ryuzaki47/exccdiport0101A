<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * EnhancedSubjectSeeder
 *
 * Canonical curriculum data for all CCDI programs (AY 2025-2026).
 * Sources: Official OBE curriculum documents provided by CCDI administration.
 *
 * Programs included:
 *   1. BSET-ECE  — Bachelor of Science in Engineering Technology (Electronics)
 *   2. BSET-EET  — Bachelor of Science in Engineering Technology (Electrical)
 *   3. ACT       — Associate in Computer Technology (Networking)
 *   4. BSCS      — Bachelor of Science in Computer Science
 *   5. BSIT      — Bachelor of Science in Information Technology
 *   6. BSIS      — Bachelor of Science in Information Systems
 *
 * Billing rules applied here:
 *   - lec_units  → billable lecture units (used for tuition calculation)
 *   - lab_units  → lab units (> 0 means lab fee charged for this subject)
 *   - NSTP subjects: lec_units set per curriculum, billing excluded in AssessmentService
 *   - PATHFIT/PE subjects: lec_units set per curriculum, billing excluded in AssessmentService
 *   - Lab fee is charged ONCE per subject where lab_units > 0, NOT per lab unit
 */
class EnhancedSubjectSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('student_enrollments')->delete();
        DB::table('subjects')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $now = now();

        $subjects = $this->allSubjects();

        // Build insert rows
        $rows = [];
        foreach ($subjects as $s) {
            $lecUnits = $s['lec_units'];
            $labUnits = $s['lab_units'];
            $rows[] = [
                'code'           => $s['code'],
                'name'           => $s['name'],
                'units'          => $lecUnits + $labUnits, // total — kept for legacy compat
                'lec_units'      => $lecUnits,
                'lab_units'      => $labUnits,
                'price_per_unit' => 0.00, // not used for billing — AssessmentService computes from fee_settings
                'year_level'     => $s['year_level'],
                'semester'       => $s['semester'],
                'course'         => $s['course'],
                'description'    => null,
                'has_lab'        => $labUnits > 0,
                'lab_fee'        => 0.00, // not used — billing uses AssessmentService::compute()
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        // Chunk inserts for performance
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('subjects')->insert($chunk);
        }

        $this->command->info('EnhancedSubjectSeeder: inserted ' . count($rows) . ' subjects.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ALL SUBJECTS
    // ─────────────────────────────────────────────────────────────────────────

    private function allSubjects(): array
    {
        return array_merge(
            $this->bsetEce(),
            $this->bsetEet(),
            $this->act(),
            $this->bscs(),
            $this->bsit(),
            $this->bsis(),
        );
    }

    // =========================================================================
    // 1. BSET-ECE — Bachelor of Science in Engineering Technology (Electronics)
    //    15 pages of curriculum, AY 2025-2026
    // =========================================================================

    private function bsetEce(): array
    {
        $c = 'BS Engineering Technology - Electronics';
        return [
            // ── 1st Year — 1st Semester ──────────────────────────────────────
            ['code'=>'ECE-GE1',      'name'=>'Purposive Communication',                     'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-GEELEC1',  'name'=>'Living in the IT Era',                        'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-GE2',      'name'=>'Mathematics in the Modern World',             'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-GE3',      'name'=>'Science, Technology & Society',               'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-ELXT110',  'name'=>'Basic Electricity and Electronics',           'lec_units'=>3,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-MATH101',  'name'=>'Calculus 1 - Differential Calculus',         'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-PHYS101',  'name'=>'Physics for Engineering Technologists',      'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-COMP101',  'name'=>'Integrated Software Applications',           'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-PATHFIT1', 'name'=>'Movement Competency Training',               'lec_units'=>2,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-NSTP1',    'name'=>'National Service Training Program 1',        'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],

            // ── 1st Year — 2nd Semester ──────────────────────────────────────
            ['code'=>'ECE-GEELEC2',  'name'=>'Peace Studies and Education',                 'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-ECE111',   'name'=>'Electronics 1: Electronics Devices and Circuits','lec_units'=>3,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-EE110',    'name'=>'DC and AC Circuits',                          'lec_units'=>3,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-CHEM101',  'name'=>'Chemistry for Engineering Technologist',     'lec_units'=>3,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-MATH102',  'name'=>'Calculus 2 - Integral Calculus',             'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-COMP102',  'name'=>'Integrated Software Applications 2',         'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-CAD1',     'name'=>'Computer-Aided Drafting',                    'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-PATHFIT2', 'name'=>'Exercise-based Fitness Activities',          'lec_units'=>2,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-NSTP2',    'name'=>'National Service Training Program 2',        'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],

            // ── 2nd Year — 1st Semester ──────────────────────────────────────
            ['code'=>'ECE-GE4',      'name'=>'The Contemporary World',                     'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-GE5',      'name'=>'Readings in the Philippine History',         'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-INDMATH1', 'name'=>'Industrial and Business Mathematics',        'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-ECE112',   'name'=>'Electronics 2: Electronics Circuits Analysis and Design','lec_units'=>3,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-ELXT120',  'name'=>'Audio-Video System, and Satellite Television Principles and Application','lec_units'=>3,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-ENGMNT',   'name'=>'Engineering Management',                     'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-BOSH',     'name'=>'Basic Occupational Safety and Health',       'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-COMP201',  'name'=>'Computer Programming 1',                     'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-PATHFIT3', 'name'=>'Outdoor and Adventure Activities',           'lec_units'=>2,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],

            // ── 2nd Year — 2nd Semester ──────────────────────────────────────
            ['code'=>'ECE-GEELEC3',  'name'=>'Philippine Indigenous Communities',          'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-GE6',      'name'=>'Ethics',                                     'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-ECE121',   'name'=>'Communication 1: Principles of Communication System','lec_units'=>3,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-ELXT130',  'name'=>'LED Display Principles and Applications',   'lec_units'=>3,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-ENTREP',   'name'=>'Technopreneurship with Basic Accounting',   'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-BT',       'name'=>'Basic Thermodynamics',                       'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-COMP202',  'name'=>'Computer Programming 2',                     'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-PE4',      'name'=>'Dance',                                      'lec_units'=>2,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],

            // ── 3rd Year — 1st Semester ──────────────────────────────────────
            ['code'=>'ECE-GE7',      'name'=>'Life and Works of Rizal',                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-GE8',      'name'=>'Understanding the Self',                    'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-ECE122',   'name'=>'Communication 2: Modulation and Coding Techniques','lec_units'=>3,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-ELXT140',  'name'=>'Industrial Electronics',                    'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-EE120',    'name'=>'Energy Conversion',                         'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-MATH104',  'name'=>'Engineering Data Analysis',                 'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-SRB',      'name'=>'Static of Rigid Bodies',                    'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-THESIS1',  'name'=>'Thesis Writing',                            'lec_units'=>2,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],

            // ── 3rd Year — 2nd Semester ──────────────────────────────────────
            ['code'=>'ECE-GE9',      'name'=>'Art Appreciation',                          'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-ECE131',   'name'=>'Digital Electronics 1: Logic Circuits and Switching Theory','lec_units'=>3,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-ELXT150',  'name'=>'CCTV, Fire Detection and Alarm System, and Power Control System','lec_units'=>3,'lab_units'=>2,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-ELXT160',  'name'=>'Photovoltaic Principle and Application',   'lec_units'=>3,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-ENVISCIE', 'name'=>'Environmental Science and Engineering',    'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-ENGECO',   'name'=>'Engineering Economics',                    'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ECE-PROJECT1', 'name'=>'Capstone Project',                         'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],

            // ── 4th Year — 1st Semester ──────────────────────────────────────
            ['code'=>'ECE-ECE132',   'name'=>'Digital Electronics 2: Microprocessor and Microcontroller Systems and Design','lec_units'=>3,'lab_units'=>1,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-ELXT180',  'name'=>'ECE Laws, Contracts, Ethics, Standard & Safety with Philippine Electrical Code','lec_units'=>4,'lab_units'=>0,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-ELXT170',  'name'=>'Fundamentals of Mechatronics',             'lec_units'=>3,'lab_units'=>2,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-EMAG',     'name'=>'Electromagnetics',                         'lec_units'=>3,'lab_units'=>0,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ECE-MATSCIE',  'name'=>'Materials Science and Engineering Technologist','lec_units'=>3,'lab_units'=>0,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],

            // ── 4th Year — 2nd Semester (OJT) ───────────────────────────────
            ['code'=>'ECE-EETOJT',   'name'=>'On-the-Job Training (720 Hours)',           'lec_units'=>12,'lab_units'=>0,'year_level'=>'4th Year','semester'=>'2nd Sem','course'=>$c],
        ];
    }

    // =========================================================================
    // 2. BSET-EET — Bachelor of Science in Engineering Technology (Electrical)
    //    16 pages of curriculum, AY 2025-2026
    // =========================================================================

    private function bsetEet(): array
    {
        $c = 'BS Engineering Technology - Electrical';
        return [
            // ── 1st Year — 1st Semester (shares GE core with ECE) ────────────
            ['code'=>'EET-GE1',      'name'=>'Purposive Communication',                    'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-GEELEC1',  'name'=>'Living in the IT Era',                       'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-GE2',      'name'=>'Mathematics in the Modern World',            'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-PHYS101',  'name'=>'Physics for Engineering Technologists',     'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-ELXT110',  'name'=>'Basic Electricity and Electronics',         'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-COMP101',  'name'=>'Integrated Software Applications',          'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-PATHFIT1', 'name'=>'Movement Competency Training',              'lec_units'=>2,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-NSTP1',    'name'=>'National Service Training Program 1',       'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],

            // ── 1st Year — 2nd Semester ──────────────────────────────────────
            ['code'=>'EET-GE3',      'name'=>'Science, Technology & Society',             'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-MATH101',  'name'=>'Calculus 1 - Differential Calculus',        'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-EE101',    'name'=>'DC and AC Circuits',                        'lec_units'=>3,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-COMP102',  'name'=>'Integrated Software Applications 2',        'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-PATHFIT2', 'name'=>'Exercise-based Fitness Activities',         'lec_units'=>2,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-NSTP2',    'name'=>'National Service Training Program 2',       'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],

            // ── 2nd Year — 1st Semester ──────────────────────────────────────
            ['code'=>'EET-GE5',      'name'=>'Readings in the Philippine History',        'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-INDMATH1', 'name'=>'Industrial and Business Mathematics',       'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-EMAG',     'name'=>'Electromagnetics',                          'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-BOSH',     'name'=>'Basic Occupational Safety & Health',        'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-EET110',   'name'=>'Renewable Energy for Sustainable Devt.',   'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-EET120',   'name'=>'Electrical Circuits',                       'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-EET130',   'name'=>'Electrical Wiring System 1',               'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-COMP201',  'name'=>'Computer Programming 1',                   'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-PATHFIT3', 'name'=>'Outdoor and Adventure Activities',         'lec_units'=>2,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],

            // ── 2nd Year — 2nd Semester ──────────────────────────────────────
            ['code'=>'EET-GEELEC3',  'name'=>'Philippine Indigenous Communities',         'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-GE6',      'name'=>'Ethics',                                    'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-EET170',   'name'=>'Electrical Wiring System 2',               'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-BT',       'name'=>'Basic Thermodynamics',                     'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-FM',       'name'=>'Fluid Mechanics',                          'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-EET140',   'name'=>'Electrical Design Estimation and Costing', 'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-EET150',   'name'=>'Electronics Circuits and Devices',         'lec_units'=>3,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-IELX',     'name'=>'Industrial Electronics',                   'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-COMP202',  'name'=>'Computer Programming 2',                   'lec_units'=>2,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-PE4',      'name'=>'Dance',                                    'lec_units'=>2,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],

            // ── 3rd Year — 1st Semester ──────────────────────────────────────
            ['code'=>'EET-GE7',      'name'=>'Life and Works of Rizal',                  'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-GE8',      'name'=>'Understanding the Self',                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-EDA1',     'name'=>'Engineering Data Analysis',                'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-FELCOM',   'name'=>'Fundamentals of Electronic Communication', 'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-LCST',     'name'=>'Logic, Circuits and Switching Theory',     'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-THESIS1',  'name'=>'Thesis Writing',                           'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-EET160',   'name'=>'Electrical Machines 1',                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-EET180',   'name'=>'EE Laws, Codes, Standards and Ethics',    'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],

            // ── 3rd Year — 2nd Semester ──────────────────────────────────────
            ['code'=>'EET-GE9',      'name'=>'Art Appreciation',                         'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-ENTREP',   'name'=>'Technopreneurship with Basic Accounting',  'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-ENGECO',   'name'=>'Engineering Economics',                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-EET190',   'name'=>'Electrical Standards and Practices',      'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-EET200',   'name'=>'Electrical Machines 2',                   'lec_units'=>3,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-EET210',   'name'=>'Power Generation and Transmission',       'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-EET220',   'name'=>'Programmable Logic Controller',            'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'EET-PROJECT1', 'name'=>'Capstone Project',                        'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],

            // ── 4th Year — 1st Semester ──────────────────────────────────────
            ['code'=>'EET-ENVISCIE', 'name'=>'Environmental Science and Engineering',   'lec_units'=>3,'lab_units'=>0,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-MATSCIE',  'name'=>'Material Science and Engineering',        'lec_units'=>3,'lab_units'=>0,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-EET230',   'name'=>'Electrical Preventive Maintenance and Repair','lec_units'=>2,'lab_units'=>1,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-EET240',   'name'=>'Electrical System and Illumination Engineering Design','lec_units'=>3,'lab_units'=>2,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-EET250',   'name'=>'Industrial Motor Control',               'lec_units'=>3,'lab_units'=>1,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'EET-EET260',   'name'=>'Instrumentation and Control',            'lec_units'=>3,'lab_units'=>1,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],

            // ── 4th Year — 2nd Semester (OJT) ───────────────────────────────
            ['code'=>'EET-OJT',      'name'=>'On-the-Job Training',                    'lec_units'=>12,'lab_units'=>0,'year_level'=>'4th Year','semester'=>'2nd Sem','course'=>$c],
        ];
    }

    // =========================================================================
    // 3. ACT — Associate in Computer Technology (Networking)
    //    11 pages of curriculum, AY 2024-2025
    // =========================================================================

    private function act(): array
    {
        $c = 'Associate in Computer Technology';
        return [
            // ── 1st Year — 1st Semester ──────────────────────────────────────
            ['code'=>'ACT-ENG1',     'name'=>'Purposive Communication',                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ACT-GEELEC1',  'name'=>'Living in the IT Era',                      'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ACT-MATH1',    'name'=>'Mathematics in the Modern World',           'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ACT-ITC101',   'name'=>'Introduction to Computing',                 'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ACT-ITC102',   'name'=>'IT Software Solutions for Business 1',     'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ACT-ITC103',   'name'=>'Computer Programming 1',                   'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ACT-ELEC1',    'name'=>'Fundamentals to Computer Systems Servicing','lec_units'=>2,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ACT-PATHFIT1', 'name'=>'Movement Competency Training',              'lec_units'=>2,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ACT-NSTP1',    'name'=>'National Service Training Program 1',       'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],

            // ── 1st Year — 2nd Semester ──────────────────────────────────────
            ['code'=>'ACT-GEELEC2',  'name'=>'Peace Studies and Education',               'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ACT-LIT1',     'name'=>'The Contemporary World',                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ACT-PHILO',    'name'=>'Understanding the Self',                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ACT-ITC104',   'name'=>'IT Software Solutions for Business 2',     'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ACT-ITC105',   'name'=>'Computer Programming 2',                   'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ACT-ITC106',   'name'=>'Information Management',                   'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ACT-ELEC2',    'name'=>'Data Communication and Networking 1',      'lec_units'=>2,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ACT-PATHFIT2', 'name'=>'Exercise-based Fitness Activities',        'lec_units'=>2,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ACT-NSTP2',    'name'=>'National Service Training Program 2',      'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],

            // ── 2nd Year — 1st Semester ──────────────────────────────────────
            ['code'=>'ACT-ETHICS',   'name'=>'Ethics',                                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ACT-HIST1',    'name'=>'Readings in the Philippine History',       'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ACT-SCIE1',    'name'=>'Science, Technology & Society',            'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ACT-GEELEC3',  'name'=>'Philippine Indigenous Communities',        'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ACT-HUM',      'name'=>'Art Appreciation',                         'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ACT-ITC201',   'name'=>'Data Structures and Algorithms',           'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ACT-ELEC3',    'name'=>'Internet Protocols',                       'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ACT-ELEC4',    'name'=>'Data Communication and Networking 2',     'lec_units'=>2,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'ACT-PATHFIT3', 'name'=>'Outdoor and Adventure Activities',        'lec_units'=>2,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],

            // ── 2nd Year — 2nd Semester ──────────────────────────────────────
            ['code'=>'ACT-ELEC5',    'name'=>'Network Security',                         'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ACT-ELEC6',    'name'=>'Network Administration and Maintenance',   'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ACT-PROF1',    'name'=>'Application Development and Emerging Technologies','lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ACT-PROF2',    'name'=>'Quantitative Methods (Modelling and Simulation)','lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ACT-SOCSCI1',  'name'=>'Life and Works of Rizal',                 'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ACT-PATHFIT4', 'name'=>'Dance',                                   'lec_units'=>2,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'ACT-OJT',      'name'=>'Internship (320 Hours)',                  'lec_units'=>5,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
        ];
    }

    // =========================================================================
    // 4. BSCS — Bachelor of Science in Computer Science
    //    February 2024 curriculum
    // =========================================================================

    private function bscs(): array
    {
        $c = 'BS Computer Science';
        return [
            // ── 1st Year — 1st Semester ──────────────────────────────────────
            ['code'=>'CS-ENG1',      'name'=>'Purposive Communication',                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-GEELEC1',   'name'=>'Living in the IT Era',                      'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-MATH1',     'name'=>'Mathematics in the Modern World',           'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-ITC101',    'name'=>'Introduction to Computing',                 'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-CSC101',    'name'=>'Computer Programming 1',                   'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-CSP103',    'name'=>'IT Software Solutions for Business',       'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-PATHFIT1',  'name'=>'Movement Competency Training',             'lec_units'=>2,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-NSTP1',     'name'=>'National Service Training Program 1',      'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],

            // ── 1st Year — 2nd Semester ──────────────────────────────────────
            ['code'=>'CS-GEELEC2',   'name'=>'Peace Studies and Education',               'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-LIT1',      'name'=>'The Contemporary World',                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-PHILO',     'name'=>'Understanding the Self',                   'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-CSC105',    'name'=>'Computer Programming 2',                   'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-CSP107',    'name'=>'Application Development and Emerging Technologies','lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-MATH2',     'name'=>'Mathematics in the Modern World 2',        'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-PATHFIT2',  'name'=>'Exercise-based Fitness Activities',        'lec_units'=>2,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-NSTP2',     'name'=>'National Service Training Program 2',      'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],

            // ── 2nd Year — 1st Semester ──────────────────────────────────────
            ['code'=>'CS-ETHICS',    'name'=>'Ethics',                                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-MATH2B',    'name'=>'Probabilities and Statistics',             'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-HIST1',     'name'=>'Readings in the Philippine History',       'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-GEELEC3',   'name'=>'Philippine Indigenous Communities',        'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-CSC201',    'name'=>'Data Structures and Algorithms',           'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-CSC202',    'name'=>'Application Development and Emerging Technologies 2','lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-CSP203',    'name'=>'Discrete Structures 1',                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-PATHFIT3',  'name'=>'Outdoor and Adventure Activities',        'lec_units'=>2,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],

            // ── 2nd Year — 2nd Semester ──────────────────────────────────────
            ['code'=>'CS-CSP204',    'name'=>'Discrete Structures 2',                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-CSC205',    'name'=>'Object Oriented Programming - Algorithms and Complexity','lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-CSC206',    'name'=>'Algorithms and Complexity',               'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-SELEC1',    'name'=>'Intro to Android Development',            'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-CCTNG',     'name'=>'Fundamentals of Accounting',              'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-HILO',      'name'=>'Understanding the Self',                  'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-SOCSCI1',   'name'=>'Life and Works of Rizal',                'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-PATHFIT4',  'name'=>'Dance',                                  'lec_units'=>2,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],

            // ── 3rd Year — 1st Semester ──────────────────────────────────────
            ['code'=>'CS-SCIE1',     'name'=>'Science, Technology & Society',           'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-CIE1',      'name'=>'Art Appreciation',                        'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-HUM',       'name'=>'Art Appreciation',                        'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-CSP301',    'name'=>'Operating Systems',                       'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-CSP302',    'name'=>'Architecture and Organization',           'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-CSP303',    'name'=>'Information Assurance and Security',     'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-CSP304',    'name'=>'Software Engineering 1',                  'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-SELEC2',    'name'=>'Intelligent Systems',                    'lec_units'=>2,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],

            // ── 3rd Year — 2nd Semester ──────────────────────────────────────
            ['code'=>'CS-CSP305',    'name'=>'Networks and Communications',             'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-CSP306',    'name'=>'Programming Languages',                  'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-CSP307',    'name'=>'Software Engineering 2',                 'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-CSP308',    'name'=>'Automata Theory and Formal Languages',   'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-CSP309',    'name'=>'Social Issues and Professional Practices','lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-SELEC3',    'name'=>'Graphics and Visual Computing',          'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-NGT',       'name'=>'Business Management and Organization',   'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],

            // ── 4th Year — 1st Semester ──────────────────────────────────────
            ['code'=>'CS-NTREP',     'name'=>'Fundamentals of Entrepreneurship',       'lec_units'=>3,'lab_units'=>0,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-CSP401',    'name'=>'Introduction to Games: Theory & Design', 'lec_units'=>2,'lab_units'=>1,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-SELEC4',    'name'=>'Systems Fundamentals',                   'lec_units'=>2,'lab_units'=>1,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-SELEC5',    'name'=>'Special Topics in CS Trends',            'lec_units'=>2,'lab_units'=>1,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-SELEC6',    'name'=>'Parallel and Distributed Computing',    'lec_units'=>2,'lab_units'=>1,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'CS-THESIS1',   'name'=>'CS Thesis 1',                           'lec_units'=>2,'lab_units'=>0,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],

            // ── 4th Year — 2nd Semester ──────────────────────────────────────
            ['code'=>'CS-THESIS2',   'name'=>'CS Thesis 2',                           'lec_units'=>2,'lab_units'=>1,'year_level'=>'4th Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'CS-SENIOROJT', 'name'=>'Practicum (162 Hours)',                 'lec_units'=>4,'lab_units'=>2,'year_level'=>'4th Year','semester'=>'2nd Sem','course'=>$c],
        ];
    }

    // =========================================================================
    // 5. BSIT — Bachelor of Science in Information Technology
    //    February 2024 curriculum, 15 pages
    // =========================================================================

    private function bsit(): array
    {
        $c = 'BS Information Technology';
        return [
            // ── 1st Year — 1st Semester ──────────────────────────────────────
            ['code'=>'IT-ENG1',      'name'=>'Purposive Communication',                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-GEELEC1',   'name'=>'Living in the IT Era',                      'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-MATH1',     'name'=>'Mathematics in the Modern World',           'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-ITC101',    'name'=>'Introduction to Computing',                 'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-ITC102',    'name'=>'IT Software Solutions for Business 1',     'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-ITC103',    'name'=>'Computer Programming 1',                   'lec_units'=>2,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-PATHFIT1',  'name'=>'Movement Competency Training',             'lec_units'=>2,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-NSTP1',     'name'=>'National Service Training Program 1',      'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],

            // ── 1st Year — 2nd Semester ──────────────────────────────────────
            ['code'=>'IT-GEELEC2',   'name'=>'Peace Studies and Education',               'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-LIT1',      'name'=>'The Contemporary World',                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-ITC104',    'name'=>'IT Software Solutions for Business 2',     'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-ITC105',    'name'=>'Computer Programming 2',                   'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-ITC106',    'name'=>'Information Management',                   'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-ITP107',    'name'=>'Introduction to Human Computer Interaction','lec_units'=>2,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-PATHFIT2',  'name'=>'Exercise-based Fitness Activities',        'lec_units'=>2,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-NSTP2',     'name'=>'National Service Training Program 2',      'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],

            // ── 2nd Year — 1st Semester ──────────────────────────────────────
            ['code'=>'IT-HIST1',     'name'=>'Readings in the Philippine History',       'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-PHILO1',    'name'=>'Understanding the Self',                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-GEELEC3',   'name'=>'Philippine Indigenous Communities',        'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-ITC201',    'name'=>'Data Structures and Algorithms',           'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-ITP202',    'name'=>'Application Development and Emerging Technologies','lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-ITP203',    'name'=>'Fundamentals of Database Systems',        'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-PATHFIT3',  'name'=>'Outdoor and Adventure Activities',        'lec_units'=>2,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],

            // ── 2nd Year — 2nd Semester ──────────────────────────────────────
            ['code'=>'IT-ETHICS',    'name'=>'Ethics',                                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-HUM',       'name'=>'Art Appreciation',                        'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-ITP301',    'name'=>'Networking 1',                             'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-ITP302',    'name'=>'Systems Integration & Architecture 1',   'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-ITELEC1',   'name'=>'Web Systems and Technologies 1',          'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-ITELEC2',   'name'=>'Platform Technologies',                   'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-PATHFIT4',  'name'=>'Dance',                                   'lec_units'=>2,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],

            // ── 3rd Year — 1st Semester ──────────────────────────────────────
            ['code'=>'IT-ENG3',      'name'=>'Research Production',                     'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-ACCTNG',    'name'=>'Fundamentals of Accounting',              'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-ITP306',    'name'=>'Networking 2',                            'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-ITP302B',   'name'=>'Systems Integration & Architecture 2',  'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-ITELEC3',   'name'=>'Web Systems and Technologies 2',         'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-ITP304',    'name'=>'Information Assurance and Security 1',   'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],

            // ── 3rd Year — 2nd Semester ──────────────────────────────────────
            ['code'=>'IT-ITP304B',   'name'=>'Information Assurance and Security 2',   'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-ITC305',    'name'=>'Discrete Mathematics',                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-ITP307',    'name'=>'Systems Administration and Maintenance', 'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-ITELEC4',   'name'=>'Web Systems and Technologies 3',         'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-ITELEC5',   'name'=>'Systems Integration & Architecture 3',  'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-MNGT',      'name'=>'Business Management and Organization',  'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'2nd Sem','course'=>$c],

            // ── 4th Year — 1st Semester ──────────────────────────────────────
            ['code'=>'IT-ENTREP',    'name'=>'Fundamentals of Entrepreneurship',       'lec_units'=>3,'lab_units'=>0,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-ITELEC6',   'name'=>'Special Topics in IT Trends',            'lec_units'=>2,'lab_units'=>1,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-ITP401',    'name'=>'Social and Professional Issues',         'lec_units'=>3,'lab_units'=>0,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-ITP402',    'name'=>'Web Systems and Technologies 4',         'lec_units'=>2,'lab_units'=>1,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-ITP403',    'name'=>'Computer Graphics',                     'lec_units'=>2,'lab_units'=>1,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IT-PROJECT1',  'name'=>'Capstone Project 1',                    'lec_units'=>2,'lab_units'=>1,'year_level'=>'4th Year','semester'=>'1st Sem','course'=>$c],

            // ── 4th Year — 2nd Semester ──────────────────────────────────────
            ['code'=>'IT-PROJECT2',  'name'=>'Capstone Project 2',                    'lec_units'=>2,'lab_units'=>1,'year_level'=>'4th Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IT-SENIOROJT', 'name'=>'Practicum (486 Hours)',                 'lec_units'=>4,'lab_units'=>2,'year_level'=>'4th Year','semester'=>'2nd Sem','course'=>$c],
        ];
    }

    // =========================================================================
    // 6. BSIS — Bachelor of Science in Information Systems
    //    June 2021 curriculum, 14 pages
    // =========================================================================

    private function bsis(): array
    {
        $c = 'BS Information Systems';
        return [
            // ── 1st Year — 1st Semester ──────────────────────────────────────
            ['code'=>'IS-ENG1',      'name'=>'Purposive Communication',                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-GEELEC1',   'name'=>'Living in the IT Era',                      'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-MATH1',     'name'=>'Mathematics in the Modern World',           'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-ISC101',    'name'=>'Introduction to Computing',                 'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-ISC102',    'name'=>'Computer Programming 1',                   'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-ISC103',    'name'=>'IT Software Solutions for Business',       'lec_units'=>2,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-PE1',       'name'=>'Physical Fitness and Gymnastics',          'lec_units'=>2,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-NSTP1',     'name'=>'National Service Training Program 1',      'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'1st Sem','course'=>$c],

            // ── 1st Year — 2nd Semester ──────────────────────────────────────
            ['code'=>'IS-GEELEC2',   'name'=>'Peace Studies and Education',               'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IS-LIT1',      'name'=>'The Contemporary World',                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IS-GEELEC3',   'name'=>'Philippine Indigenous Communities',        'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IS-PHILO',     'name'=>'Understanding the Self',                   'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IS-ISC104',    'name'=>'Computer Programming 2',                   'lec_units'=>2,'lab_units'=>1,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IS-ISP105',    'name'=>'Fundamentals of Information Systems',     'lec_units'=>2,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IS-PE2',       'name'=>'Individual and Team Sports',              'lec_units'=>2,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IS-NSTP2',     'name'=>'National Service Training Program 2',     'lec_units'=>3,'lab_units'=>0,'year_level'=>'1st Year','semester'=>'2nd Sem','course'=>$c],

            // ── 2nd Year — 1st Semester ──────────────────────────────────────
            ['code'=>'IS-ETHICS',    'name'=>'Ethics',                                   'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-HIST1',     'name'=>'Readings in the Philippine History',      'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-ISC201',    'name'=>'Data Structures and Algorithms',          'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-ISP202',    'name'=>'Professional Issues in Information Systems','lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-ISP203',    'name'=>'Infrastructure and Network Technologies', 'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-ISC204',    'name'=>'Information Management',                  'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-ISP205',    'name'=>'Organization and Management Concepts',   'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-PE3',       'name'=>'Rhythmic and Folkdances',                'lec_units'=>2,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'1st Sem','course'=>$c],

            // ── 2nd Year — 2nd Semester ──────────────────────────────────────
            ['code'=>'IS-HUM',       'name'=>'Art Appreciation',                        'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IS-SOCSCI1',   'name'=>'Life and Works of Rizal',                'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IS-ACCTNG',    'name'=>'Fundamentals of Accounting',             'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IS-SCIE1',     'name'=>'Science, Technology and Society',        'lec_units'=>2,'lab_units'=>1,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IS-ISP206',    'name'=>'System Analysis and Design',             'lec_units'=>3,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IS-ISP207',    'name'=>'Financial Management',                   'lec_units'=>2,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],
            ['code'=>'IS-PE4',       'name'=>'Recreational Sports',                   'lec_units'=>2,'lab_units'=>0,'year_level'=>'2nd Year','semester'=>'2nd Sem','course'=>$c],

            // ── 3rd Year — 1st Semester ──────────────────────────────────────
            ['code'=>'IS-ENG4',      'name'=>'Research Production',                    'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-ISP301',    'name'=>'Enterprise Architecture',                'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-ISP302',    'name'=>'Business Process Management',            'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-ISP303',    'name'=>'Quantitative Methods',                   'lec_units'=>2,'lab_units'=>1,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-ELEC1IS',   'name'=>'IT Security and Management',             'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
            ['code'=>'IS-ENTREP',    'name'=>'Fundamentals of Entrepreneurship',       'lec_units'=>3,'lab_units'=>0,'year_level'=>'3rd Year','semester'=>'1st Sem','course'=>$c],
        ];
    }
}