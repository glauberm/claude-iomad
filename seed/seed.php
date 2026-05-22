<?php
/**
 * IOMAD Demo Seed Script
 *
 * Creates 2 school tenants, 3 courses each, 5 students each, 1 quiz per course
 * with 5 MCQ questions, and pre-seeded quiz attempts (mixed pass/fail).
 *
 * Run: php /seed/seed.php
 */

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/local/iomad/lib/iomad.php');
require_once($CFG->dirroot . '/local/iomad/lib/company.php');

cli_writeln('=== IOMAD Demo Seed ===');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function seed_log(string $msg): void {
    cli_writeln('[seed] ' . $msg);
}

function seed_get_or_create_category(string $name, string $idnumber): int {
    global $DB;
    if ($cat = $DB->get_record('course_categories', ['idnumber' => $idnumber])) {
        seed_log("Category already exists: {$name}");
        return (int) $cat->id;
    }
    $cat = core_course_category::create([
        'name'     => $name,
        'idnumber' => $idnumber,
        'parent'   => 0,
        'visible'  => 1,
    ]);
    seed_log("Created category: {$name} (id={$cat->id})");
    return (int) $cat->id;
}

function seed_create_company(string $name, string $shortname, int $catid): int {
    global $DB;
    if ($existing = $DB->get_record('company', ['shortname' => $shortname])) {
        seed_log("Company already exists: {$name}");
        return (int) $existing->id;
    }

    $companyid = $DB->insert_record('company', (object)[
        'name'      => $name,
        'shortname' => $shortname,
        'city'      => 'Springfield',
        'country'   => 'US',
        'parentid'  => 0,
        'category'  => $catid,
    ]);

    // Required: create the default top-level department
    company::initialise_departments($companyid);

    // Required: create a user_info_category for company profile fields
    $catid = $DB->insert_record('user_info_category', (object)[
        'name'      => $name,
        'sortorder' => $companyid,
    ]);
    $DB->set_field('company', 'profileid', $catid, ['id' => $companyid]);

    // Required: create the certificate record IOMAD expects
    $DB->insert_record('companycertificate', [
        'companyid'    => $companyid,
        'uselogo'      => 1,
        'usesignature' => 1,
        'useborder'    => 1,
        'usewatermark' => 1,
        'showgrade'    => 1,
    ]);

    seed_log("Created company: {$name} (id={$companyid})");
    return (int) $companyid;
}

function seed_create_course(string $fullname, string $shortname, int $catid, string $idnumber): stdClass {
    global $DB;
    if ($c = $DB->get_record('course', ['idnumber' => $idnumber])) {
        seed_log("Course already exists: {$fullname}");
        return $c;
    }
    $data                   = new stdClass();
    $data->fullname         = $fullname;
    $data->shortname        = $shortname;
    $data->idnumber         = $idnumber;
    $data->category         = $catid;
    $data->format           = 'topics';
    $data->numsections      = 3;
    $data->enablecompletion = 1;
    $data->visible          = 1;
    $data->startdate        = time();
    $course = create_course($data);
    seed_log("Created course: {$fullname} (id={$course->id})");
    return $course;
}

function seed_assign_course_to_company(int $courseid, int $companyid): void {
    global $DB;
    if (!$DB->record_exists('company_course', ['companyid' => $companyid, 'courseid' => $courseid])) {
        $rootdept = company::get_company_parentnode($companyid);
        $DB->insert_record('company_course', (object)[
            'companyid'    => $companyid,
            'courseid'     => $courseid,
            'departmentid' => $rootdept->id,
        ]);
    }
    if (!$DB->record_exists('iomad_courses', ['courseid' => $courseid])) {
        $DB->insert_record('iomad_courses', (object)[
            'courseid'       => $courseid,
            'licensed'       => 0,
            'shared'         => 0,
            'validlength'    => 0,
            'warnexpire'     => 0,
            'warncompletion' => 0,
            'notifyperiod'   => 0,
            'expireafter'    => 0,
            'warnnotstarted' => 0,
            'hasgrade'       => 1,
        ]);
    }
}

function seed_create_student(
    string $firstname,
    string $lastname,
    string $email,
    string $username
): stdClass {
    global $DB;
    if ($u = $DB->get_record('user', ['username' => $username])) {
        seed_log("User already exists: {$username}");
        return $u;
    }
    $user               = new stdClass();
    $user->auth         = 'manual';
    $user->confirmed    = 1;
    $user->policyagreed = 1;
    $user->mnethostid   = 1;
    $user->username     = $username;
    $user->password     = hash_internal_user_password('Student123!');
    $user->email        = $email;
    $user->firstname    = $firstname;
    $user->lastname     = $lastname;
    $user->city         = 'Springfield';
    $user->country      = 'US';
    $user->lang         = 'en';
    $user->timecreated  = time();
    $user->timemodified = time();
    $user->id           = $DB->insert_record('user', $user);
    seed_log("Created student: {$firstname} {$lastname} (id={$user->id})");
    return $user;
}

function seed_assign_user_to_company(int $userid, int $companyid): void {
    $company = new company($companyid);
    $company->assign_user_to_company($userid);
}

function seed_enrol_student(int $userid, int $courseid): void {
    global $DB;
    $enrolplugin = enrol_get_plugin('manual');

    $instance = $DB->get_record('enrol', [
        'courseid' => $courseid,
        'enrol'    => 'manual',
        'status'   => ENROL_INSTANCE_ENABLED,
    ]);

    if (!$instance) {
        $course     = get_course($courseid);
        $instanceid = $enrolplugin->add_default_instance($course);
        $instance   = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
    }

    $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
    $enrolplugin->enrol_user($instance, $userid, $studentrole->id, time());
}

function seed_get_or_create_question_category(int $courseid, string $name): int {
    global $DB;
    $context = context_course::instance($courseid);

    if ($cat = $DB->get_record('question_categories', ['contextid' => $context->id, 'name' => $name])) {
        return (int) $cat->id;
    }

    $parent = $DB->get_record('question_categories', ['contextid' => $context->id, 'parent' => 0]);

    $cat             = new stdClass();
    $cat->name       = $name;
    $cat->contextid  = $context->id;
    $cat->info       = '';
    $cat->infoformat = FORMAT_MOODLE;
    $cat->parent     = $parent ? (int) $parent->id : 0;
    $cat->sortorder  = 999;
    $cat->stamp      = make_unique_id_code();
    $cat->id         = $DB->insert_record('question_categories', $cat);
    return (int) $cat->id;
}

function seed_create_mcq(
    int $categoryid,
    string $questiontext,
    array $answers,
    int $correctindex
): int {
    global $DB;

    // Idempotency: check via question_bank_entries join
    $existing = $DB->get_record_sql(
        "SELECT q.id FROM {question} q
           JOIN {question_bank_entries} qbe ON qbe.id IN (
               SELECT questionbankentryid FROM {question_versions} WHERE questionid = q.id
           )
          WHERE qbe.questioncategoryid = :catid
            AND q.questiontext = :qtext
            AND q.qtype = 'multichoice'",
        ['catid' => $categoryid, 'qtext' => $questiontext]
    );
    if ($existing) {
        return (int) $existing->id;
    }

    $now = time();

    // In Moodle 4.x, 'question' table has no category/version/hidden fields
    $question                        = new stdClass();
    $question->parent                = 0;
    $question->name                  = mb_substr(strip_tags($questiontext), 0, 100);
    $question->questiontext          = $questiontext;
    $question->questiontextformat    = FORMAT_HTML;
    $question->generalfeedback       = '';
    $question->generalfeedbackformat = FORMAT_HTML;
    $question->defaultmark           = 1.0;
    $question->penalty               = 0.3333333;
    $question->qtype                 = 'multichoice';
    $question->length                = 1;
    $question->stamp                 = make_unique_id_code();
    $question->timecreated           = $now;
    $question->timemodified          = $now;
    $question->createdby             = 2;
    $question->modifiedby            = 2;
    $question->id                    = $DB->insert_record('question', $question);

    $mc                                  = new stdClass();
    $mc->questionid                      = $question->id;
    $mc->layout                          = 0;
    $mc->single                          = 1;
    $mc->shuffleanswers                  = 1;
    $mc->correctfeedback                 = '<p>Correct!</p>';
    $mc->correctfeedbackformat           = FORMAT_HTML;
    $mc->partiallycorrectfeedback        = '';
    $mc->partiallycorrectfeedbackformat  = FORMAT_HTML;
    $mc->incorrectfeedback               = '<p>Incorrect. Review the material and try again.</p>';
    $mc->incorrectfeedbackformat         = FORMAT_HTML;
    $mc->answernumbering                 = 'abc';
    $mc->showstandardinstruction         = 1;
    $DB->insert_record('qtype_multichoice_options', $mc);

    foreach ($answers as $i => $answertext) {
        $answer               = new stdClass();
        $answer->question     = $question->id;
        $answer->answer       = $answertext;
        $answer->answerformat = FORMAT_HTML;
        $answer->fraction     = ($i === $correctindex) ? 1.0 : 0.0;
        $answer->feedback     = ($i === $correctindex)
            ? 'Correct! Well done.'
            : 'Incorrect. That is not the right answer.';
        $answer->feedbackformat = FORMAT_HTML;
        $DB->insert_record('question_answers', $answer);
    }

    // Moodle 4.x question bank versioning tables
    $entry                       = new stdClass();
    $entry->questioncategoryid   = $categoryid;
    $entry->idnumber             = null;
    $entry->ownerid              = 2;
    $entry->id                   = $DB->insert_record('question_bank_entries', $entry);

    $version                       = new stdClass();
    $version->questionbankentryid  = $entry->id;
    $version->version              = 1;
    $version->questionid           = $question->id;
    $version->status               = 'ready';
    $DB->insert_record('question_versions', $version);

    return (int) $question->id;
}

function seed_create_quiz(stdClass $course, string $quizname): stdClass {
    global $DB;

    if ($existing = $DB->get_record('quiz', ['course' => $course->id, 'name' => $quizname])) {
        seed_log("Quiz already exists: {$quizname}");
        return $existing;
    }

    $now = time();

    $quiz                         = new stdClass();
    $quiz->course                 = $course->id;
    $quiz->name                   = $quizname;
    $quiz->intro                  = '';
    $quiz->introformat            = FORMAT_HTML;
    $quiz->timeopen               = 0;
    $quiz->timeclose              = 0;
    $quiz->timelimit              = 0;
    $quiz->preferredbehaviour     = 'deferredfeedback';
    $quiz->attempts               = 0;
    $quiz->grademethod            = QUIZ_GRADEHIGHEST;
    $quiz->decimalpoints          = 2;
    $quiz->questiondecimalpoints  = -1;
    $quiz->reviewattempt          = 0;
    $quiz->reviewcorrectness      = 0;
    $quiz->reviewmarks            = 0;
    $quiz->reviewspecificfeedback = 0;
    $quiz->reviewgeneralfeedback  = 0;
    $quiz->reviewrightanswer      = 0;
    $quiz->reviewoverallfeedback  = 0;
    $quiz->questionsperpage       = 5;
    $quiz->shuffleanswers         = 1;
    $quiz->sumgrades              = 0;
    $quiz->grade                  = 100.0;
    $quiz->timecreated            = $now;
    $quiz->timemodified           = $now;
    $quiz->id                     = $DB->insert_record('quiz', $quiz);

    // Every quiz needs a default section before quiz_add_quiz_question will work
    $DB->insert_record('quiz_sections', (object)[
        'quizid'           => $quiz->id,
        'firstslot'        => 1,
        'heading'          => '',
        'shufflequestions' => 0,
    ]);

    // course_modules.section stores the section row ID, not the section number
    $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 1],
        '*', MUST_EXIST);

    $module       = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);
    $cm           = new stdClass();
    $cm->course   = $course->id;
    $cm->module   = $module->id;
    $cm->instance = $quiz->id;
    $cm->section  = $section->id;
    $cm->visible  = 1;
    $cm->added    = $now;
    $cm->id       = $DB->insert_record('course_modules', $cm);

    $sequence = $section->sequence ? $section->sequence . ',' . $cm->id : (string) $cm->id;
    $DB->set_field('course_sections', 'sequence', $sequence, ['id' => $section->id]);

    rebuild_course_cache($course->id, true);

    seed_log("Created quiz: {$quizname} (id={$quiz->id})");
    return $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
}

function seed_add_question_to_quiz(stdClass $quiz, int $questionid): void {
    // quiz_add_quiz_question is idempotent — it checks question_references internally
    quiz_add_quiz_question($questionid, $quiz, 1, 1.0);
}

function seed_create_quiz_attempt(
    int $userid,
    stdClass $quiz,
    array $questionids,
    bool $passing
): void {
    global $DB;

    if ($DB->record_exists('quiz_attempts', ['quiz' => $quiz->id, 'userid' => $userid])) {
        return;
    }

    $now = time() - rand(86400, 7 * 86400);

    $cm      = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course);
    $context = context_module::instance($cm->id);

    $usage                     = new stdClass();
    $usage->contextid          = $context->id;
    $usage->component          = 'mod_quiz';
    $usage->preferredbehaviour = 'deferredfeedback';
    $usage->id                 = $DB->insert_record('question_usages', $usage);

    $attempt                   = new stdClass();
    $attempt->quiz             = $quiz->id;
    $attempt->userid           = $userid;
    $attempt->attempt          = 1;
    $attempt->uniqueid         = $usage->id;
    // layout uses slot numbers (quiz_slots.slot), not question IDs
    $slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot ASC', 'slot');
    $attempt->layout           = implode(',', array_keys($slots)) . ',0';
    $attempt->currentpage      = 0;
    $attempt->preview          = 0;
    $attempt->state            = 'finished';
    $attempt->timestart        = $now;
    $attempt->timefinish       = $now + rand(600, 1800);
    $attempt->timemodified     = $now + rand(600, 1800);
    $attempt->timecheckstate   = null;
    $attempt->gradednotificationsenttime = null;
    $attempt->sumgrades        = $passing ? 4.0 : 1.0;
    $attempt->id               = $DB->insert_record('quiz_attempts', $attempt);

    // In Moodle 4.x, quiz_slots has no questionid — resolve via question_references
    $slots = $DB->get_records_sql(
        "SELECT qs.id, qs.slot, qs.maxmark, qv.questionid
           FROM {quiz_slots} qs
           JOIN {question_references} qr ON qr.itemid = qs.id
                AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
           JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
           JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
          WHERE qs.quizid = :quizid
          ORDER BY qs.slot ASC",
        ['quizid' => $quiz->id]
    );
    foreach ($slots as $slot) {
        $qa                  = new stdClass();
        $qa->questionusageid = $usage->id;
        $qa->slot            = $slot->slot;
        $qa->behaviour       = 'deferredfeedback';
        $qa->questionid      = $slot->questionid;
        $qa->variant         = 1;
        $qa->maxmark         = $slot->maxmark;
        $qa->minfraction     = 0.0;
        $qa->maxfraction     = 1.0;
        $qa->flagged         = 0;
        $qa->questionsummary = '';
        $qa->rightanswer     = '';
        $qa->responsesummary = '';
        $qa->timemodified    = $now;
        $qa->id              = $DB->insert_record('question_attempts', $qa);

        // passing: first 4 correct; failing: only first correct
        $isCorrect = $passing ? ($slot->slot <= 4) : ($slot->slot === 1);
        $fraction  = $isCorrect ? 1.0 : 0.0;

        $step1                    = new stdClass();
        $step1->questionattemptid = $qa->id;
        $step1->sequencenumber    = 0;
        $step1->state             = 'todo';
        $step1->fraction          = null;
        $step1->timecreated       = $now;
        $step1->userid            = $userid;
        $step1->id                = $DB->insert_record('question_attempt_steps', $step1);

        $step2                    = new stdClass();
        $step2->questionattemptid = $qa->id;
        $step2->sequencenumber    = 1;
        $step2->state             = $isCorrect ? 'gradedright' : 'gradedwrong';
        $step2->fraction          = $fraction;
        $step2->timecreated       = $now + 600;
        $step2->userid            = $userid;
        $step2->id                = $DB->insert_record('question_attempt_steps', $step2);

        $answers = $DB->get_records('question_answers', ['question' => $slot->questionid]);
        $chosen  = null;
        foreach ($answers as $a) {
            if ($isCorrect && (float) $a->fraction > 0.0) {
                $chosen = $a;
                break;
            }
            if (!$isCorrect && (float) $a->fraction === 0.0) {
                $chosen = $a;
                break;
            }
        }
        if ($chosen) {
            $DB->insert_record('question_attempt_step_data', (object)[
                'attemptstepid' => $step2->id,
                'name'          => 'answer',
                'value'         => (string) $chosen->id,
            ]);
        }
    }

    $DB->insert_record('quiz_grades', (object)[
        'quiz'         => $quiz->id,
        'userid'       => $userid,
        'grade'        => $passing ? 80.0 : 20.0,
        'timemodified' => $now,
    ]);
}

// ---------------------------------------------------------------------------
// Quiz content
// ---------------------------------------------------------------------------

$QUIZ_CONTENT = [
    'SHS-MATH101' => [
        'name' => 'Mathematics 101 — Assessment Quiz',
        'questions' => [
            ['<p>What is the square root of 144?</p>',                              ['10', '11', '12', '13'],                                                                                     2],
            ['<p>If x + 7 = 15, what is x?</p>',                                   ['6', '7', '8', '9'],                                                                                         2],
            ['<p>What is 15% of 200?</p>',                                          ['20', '25', '30', '35'],                                                                                     2],
            ['<p>Which of the following is a prime number?</p>',                    ['9', '15', '17', '21'],                                                                                      2],
            ['<p>What is the area of a rectangle with sides 6m and 4m?</p>',        ['10 m²', '20 m²', '24 m²', '48 m²'],                                                                        2],
        ],
    ],
    'SHS-ENGLIT' => [
        'name' => 'English Literature — Assessment Quiz',
        'questions' => [
            ['<p>Who wrote <em>Romeo and Juliet</em>?</p>',                         ['Charles Dickens', 'William Shakespeare', 'Jane Austen', 'Mark Twain'],                                     1],
            ['<p>In <em>To Kill a Mockingbird</em>, who is the narrator?</p>',      ['Atticus Finch', 'Tom Robinson', 'Scout Finch', 'Boo Radley'],                                              2],
            ['<p>Which device is used in "The wind whispered through the trees"?</p>', ['Metaphor', 'Simile', 'Personification', 'Alliteration'],                                                2],
            ['<p>What is a sonnet?</p>',                                            ['A 14-line poem with a fixed rhyme scheme', 'A poem with no fixed structure', 'A long narrative poem', 'A poem that does not rhyme'], 0],
            ['<p>Who wrote <em>1984</em>?</p>',                                     ['Aldous Huxley', 'George Orwell', 'Ray Bradbury', 'H.G. Wells'],                                            1],
        ],
    ],
    'SHS-HISCIV' => [
        'name' => 'History & Civics — Assessment Quiz',
        'questions' => [
            ['<p>In which year did World War II end?</p>',                          ['1943', '1944', '1945', '1946'],                                                                             2],
            ['<p>How many branches does the US federal government have?</p>',       ['2', '3', '4', '5'],                                                                                        1],
            ['<p>Which document begins with "We the People"?</p>',                 ['The Declaration of Independence', 'The Emancipation Proclamation', 'The Bill of Rights', 'The US Constitution'], 3],
            ['<p>Who was the first President of the United States?</p>',            ['John Adams', 'Thomas Jefferson', 'George Washington', 'Benjamin Franklin'],                                2],
            ['<p>What is the primary role of the judicial branch?</p>',             ['To create laws', 'To enforce laws', 'To interpret laws', 'To fund public services'],                       2],
        ],
    ],
    'SHA-MATH101' => [
        'name' => 'Mathematics 101 — Assessment Quiz',
        'questions' => [
            ['<p>What is 7 × 8?</p>',                                               ['48', '54', '56', '64'],                                                                                    2],
            ['<p>What is the perimeter of a square with side length 5cm?</p>',      ['10 cm', '15 cm', '20 cm', '25 cm'],                                                                        2],
            ['<p>Solve for y: 3y − 9 = 0</p>',                                      ['1', '2', '3', '4'],                                                                                        2],
            ['<p>What is the value of π to two decimal places?</p>',                ['3.12', '3.14', '3.16', '3.18'],                                                                            1],
            ['<p>What is 25% of 80?</p>',                                            ['15', '18', '20', '25'],                                                                                    2],
        ],
    ],
    'SHA-ENGLIT' => [
        'name' => 'English Literature — Assessment Quiz',
        'questions' => [
            ['<p>What is a story told from the first-person point of view called?</p>', ['Third-person omniscient', 'First-person narrative', 'Second-person narrative', 'Third-person limited'], 1],
            ['<p>Which is an example of a simile?</p>',                             ['The moon is a silver coin.', 'She ran like the wind.', 'The stars danced in the sky.', 'His heart is stone.'], 1],
            ['<p>Who wrote <em>Pride and Prejudice</em>?</p>',                      ['Charlotte Brontë', 'Emily Brontë', 'Jane Austen', 'Mary Shelley'],                                         2],
            ['<p>What is the climax of a story?</p>',                               ['The introduction of characters', 'The falling action', 'The turning point of highest tension', 'The resolution'], 2],
            ['<p>Which of the following is NOT a type of poem?</p>',                ['Haiku', 'Limerick', 'Soliloquy', 'Sonnet'],                                                                 2],
        ],
    ],
    'SHA-HISCIV' => [
        'name' => 'History & Civics — Assessment Quiz',
        'questions' => [
            ['<p>What year did the American Civil War begin?</p>',                  ['1857', '1859', '1861', '1863'],                                                                             2],
            ['<p>What is the supreme law of the United States?</p>',               ['The Declaration of Independence', 'The Constitution', 'The Bill of Rights', 'The Federalist Papers'],      1],
            ['<p>Which amendment abolished slavery in the United States?</p>',      ['The 13th Amendment', 'The 14th Amendment', 'The 15th Amendment', 'The 19th Amendment'],                    0],
            ['<p>How often are US Presidential elections held?</p>',                ['Every 2 years', 'Every 4 years', 'Every 6 years', 'Every 8 years'],                                        1],
            ['<p>What does the legislative branch of the US government do?</p>',    ['Interprets laws', 'Enforces laws', 'Creates laws', 'Elects the President'],                                2],
        ],
    ],
];

// ---------------------------------------------------------------------------
// Main execution
// ---------------------------------------------------------------------------

seed_log('--- Creating course categories ---');
$shs_cat = seed_get_or_create_category('Springfield High School', 'shs');
$sha_cat = seed_get_or_create_category('Shelbyville Academy', 'sha');

seed_log('--- Creating companies ---');
$shs_id = seed_create_company('Springfield High School', 'shs', $shs_cat);
$sha_id = seed_create_company('Shelbyville Academy', 'sha', $sha_cat);

seed_log('--- Creating courses ---');
$courses = [
    'SHS-MATH101' => seed_create_course('Mathematics 101',    'shs-math101', $shs_cat, 'SHS-MATH101'),
    'SHS-ENGLIT'  => seed_create_course('English Literature', 'shs-englit',  $shs_cat, 'SHS-ENGLIT'),
    'SHS-HISCIV'  => seed_create_course('History & Civics',   'shs-hisciv',  $shs_cat, 'SHS-HISCIV'),
    'SHA-MATH101' => seed_create_course('Mathematics 101',    'sha-math101', $sha_cat, 'SHA-MATH101'),
    'SHA-ENGLIT'  => seed_create_course('English Literature', 'sha-englit',  $sha_cat, 'SHA-ENGLIT'),
    'SHA-HISCIV'  => seed_create_course('History & Civics',   'sha-hisciv',  $sha_cat, 'SHA-HISCIV'),
];

seed_log('--- Assigning courses to companies ---');
foreach (['SHS-MATH101', 'SHS-ENGLIT', 'SHS-HISCIV'] as $key) {
    seed_assign_course_to_company($courses[$key]->id, $shs_id);
}
foreach (['SHA-MATH101', 'SHA-ENGLIT', 'SHA-HISCIV'] as $key) {
    seed_assign_course_to_company($courses[$key]->id, $sha_id);
}

seed_log('--- Creating students ---');
$shs_students = [
    'bart'     => seed_create_student('Bart',     'Simpson',    'bart@shs.example',     'bart.simpson'),
    'lisa'     => seed_create_student('Lisa',     'Simpson',    'lisa@shs.example',     'lisa.simpson'),
    'milhouse' => seed_create_student('Milhouse', 'Van Houten', 'milhouse@shs.example', 'milhouse.vanhouten'),
    'nelson'   => seed_create_student('Nelson',   'Muntz',      'nelson@shs.example',   'nelson.muntz'),
    'martin'   => seed_create_student('Martin',   'Prince',     'martin@shs.example',   'martin.prince'),
];
$sha_students = [
    'shelby'   => seed_create_student('Shelby', 'Brown',    'shelby@sha.example',  'shelby.brown'),
    'amber'    => seed_create_student('Amber',  'Davis',    'amber@sha.example',   'amber.davis'),
    'jake'     => seed_create_student('Jake',   'Wilson',   'jake@sha.example',    'jake.wilson'),
    'chloe'    => seed_create_student('Chloe',  'Martinez', 'chloe@sha.example',   'chloe.martinez'),
    'ethan'    => seed_create_student('Ethan',  'Taylor',   'ethan@sha.example',   'ethan.taylor'),
];

seed_log('--- Assigning and enrolling students ---');
$shs_course_keys = ['SHS-MATH101', 'SHS-ENGLIT', 'SHS-HISCIV'];
foreach ($shs_students as $s) {
    seed_assign_user_to_company($s->id, $shs_id);
    foreach ($shs_course_keys as $key) {
        seed_enrol_student($s->id, $courses[$key]->id);
    }
}
$sha_course_keys = ['SHA-MATH101', 'SHA-ENGLIT', 'SHA-HISCIV'];
foreach ($sha_students as $s) {
    seed_assign_user_to_company($s->id, $sha_id);
    foreach ($sha_course_keys as $key) {
        seed_enrol_student($s->id, $courses[$key]->id);
    }
}

seed_log('--- Creating quizzes and questions ---');
$quizzes     = [];
$questionIds = [];

foreach ($QUIZ_CONTENT as $courseKey => $quizDef) {
    $course = $courses[$courseKey];
    $quiz   = seed_create_quiz($course, $quizDef['name']);
    $quizzes[$courseKey] = $quiz;

    $catid = seed_get_or_create_question_category($course->id, "{$courseKey} Questions");

    $qids = [];
    foreach ($quizDef['questions'] as [$text, $answers, $correct]) {
        $qid  = seed_create_mcq($catid, $text, $answers, $correct);
        seed_add_question_to_quiz($quiz, $qid);
        $qids[] = $qid;
    }

    $quiz = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
    $quizobj = \mod_quiz\quiz_settings::create($quiz->id);
    \mod_quiz\grade_calculator::create($quizobj)->recompute_quiz_sumgrades();
    $quiz = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
    $quizzes[$courseKey] = $quiz;
    $questionIds[$courseKey] = $qids;
    seed_log("Quiz ready: {$quizDef['name']} with " . count($qids) . " questions");
}

seed_log('--- Seeding quiz attempts ---');

// Lisa, Martin (SHS) and Amber, Ethan (SHA) pass; others fail
$shs_passing = ['lisa', 'martin'];
$sha_passing = ['amber', 'ethan'];

foreach ($shs_course_keys as $courseKey) {
    $quiz = $quizzes[$courseKey];
    $qids = $questionIds[$courseKey];
    foreach ($shs_students as $key => $student) {
        $passing = in_array($key, $shs_passing, true);
        seed_create_quiz_attempt($student->id, $quiz, $qids, $passing);
        seed_log("  {$student->firstname} {$student->lastname} / {$courseKey}: " . ($passing ? 'PASS' : 'FAIL'));
    }
}
foreach ($sha_course_keys as $courseKey) {
    $quiz = $quizzes[$courseKey];
    $qids = $questionIds[$courseKey];
    foreach ($sha_students as $key => $student) {
        $passing = in_array($key, $sha_passing, true);
        seed_create_quiz_attempt($student->id, $quiz, $qids, $passing);
        seed_log("  {$student->firstname} {$student->lastname} / {$courseKey}: " . ($passing ? 'PASS' : 'FAIL'));
    }
}

cli_writeln('');
cli_writeln('=== Seed complete! ===');
cli_writeln('  Companies : 2 (Springfield High School, Shelbyville Academy)');
cli_writeln('  Courses   : 6 (3 per school)');
cli_writeln('  Students  : 10 (5 per school, password: Student123!)');
cli_writeln('  Quizzes   : 6 (1 per course, 5 MCQ each)');
cli_writeln('  Attempts  : 30 (each student attempted every quiz in their school)');
cli_writeln('');
cli_writeln('Login: http://localhost:8080  |  admin / see .env MOODLE_ADMIN_PASS');
