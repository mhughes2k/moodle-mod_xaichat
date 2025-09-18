<?php
define("NO_OUTPUT_BUFFERING", true);
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Prints an instance of mod_xaichat.
 *
 * @package     mod_xaichat
 * @copyright   2024 Michael Hughes <michaelhughes@strath.ac.uk>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');

use local_ai\api;
use local_ai\aiclient;
use mod_xaichat\aichatform;

// Course module id.
$id = optional_param('id', 0, PARAM_INT);

// Activity instance id.
$x = optional_param('x', 0, PARAM_INT);

if ($id) {
    $cm = get_coursemodule_from_id('xaichat', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
    $moduleinstance = $DB->get_record('xaichat', array('id' => $cm->instance), '*', MUST_EXIST);
} else {
    $moduleinstance = $DB->get_record('xaichat', array('id' => $x), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $moduleinstance->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('xaichat', $moduleinstance->id, $course->id, false, MUST_EXIST);
}

require_login($course, true, $cm);

$PAGE->set_url('/mod/xaichat/view.php', ['id' => $cm->id]);

$modulecontext = context_module::instance($cm->id);

//$aicontext = $_SESSION[$aicontextkey];

$aicontextkey = "mod_xaichat:context:{$cm->id}:{$USER->id}";
if (!isset($_SESSION[$aicontextkey])) {
    $_SESSION[$aicontextkey] = [
        'messages'=> [], // Reset to no messages
        'conversation' => [],
    ];
}
if (!isset($_SESSION[$aicontextkey]['conversation'])) {
    $_SESSION[$aicontextkey]['conversation'] = [];
}

$event = \mod_xaichat\event\course_module_viewed::create(array(
    'objectid' => $moduleinstance->id,
    'context' => $modulecontext
));
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('xaichat', $moduleinstance);
$event->trigger();

$PAGE->set_title(format_string($moduleinstance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($modulecontext);

$userpic = $OUTPUT->render(new \user_picture($USER)). fullname($USER);
$aipic = "ai";

echo $OUTPUT->header();
//var_dump ($aiprovider->get_settings_for_user($cm, $USER));
$chatform = new aichatform();
// Get an AI Manager instance.
$chatmanager = new \local_ai_manager\manager('chat');
$embeddingmanager = new \local_ai_manager\manager('embedding');
$ragmanager = new \local_ai_manager\manager('rag');

if ($data = $chatform->get_data()) {
    if (isset($data->restartbutton)) {
        $_SESSION[$aicontextkey] = [
            'messages'=> $aiprovider->generate_system_prompts($cm, $USER),
            'conversation' => [],
        ];
        redirect(new \moodle_url('/mod/xaichat/view.php', array('id' => $cm->id)));
    }
    $stepnow = 0;
    $totalsteps = 4;
    
    $progress = new \progress_bar();
    $progress->create();
    if (empty($_SESSION[$aicontextkey]['messages'])) {
        // If the user has not made any prompts yet, we need to prime the interaction with
        // a bunch of system and context specific prompts to constrain behaviour.
        $totalsteps++;
        $progress->update(1, $totalsteps,'Processing System Prompts');

        $_SESSION[$aicontextkey]['messages'] = [];//$aiprovider->generate_system_prompts($cm, $USER);
    }
    $progress->update(1, $totalsteps,'Looking for relevant context');

    $search = \core_search\manager::instance(true, true);

    // Some of these values can't be "trusted" to the end user to supply, via something
    // like a form, nor can they be entirely left to the plugin developer.
    $settings = [];//$aiprovider->get_settings_for_user($cm, $USER);
    $settings['userquery'] = $data->userprompt;
    // This limits the plugin's search scope.
    $settings['courseids'] = [$course->id]; 

    $embeddingrequest = $embeddingmanager->perform_request($data->userprompt, 'local_xaichat', $modulecontext->id);
    $embedding = $embeddingrequest->get_content();

    $ragrequest = $ragmanager->perform_request($embedding, 'local_xaichat', $modulecontext->id);
    $docs = $ragrequest->get_content();

    $prompt = $data->userprompt;
    if (!empty($docs)) {
        debugging("Got RAG content returned:" . $docs);
        $prompt = "Use the following information\n\n{$docs} to answer: \n\n{$prompt}";
    }

    $_SESSION[$aicontextkey]['messages'] = $prompt; // Store the "real" prompt.

    // Render the user's prompt and add it to the "conversation" for display.
    $conversationmessage = (object) [ 
        'content' => $data->userprompt,
        'role' => $userpic 
    ];
    $_SESSION[$aicontextkey]['conversation'][] = $conversationmessage;

    // Pass the whole context over the AI to summarise.    
    $progress->update(3, $totalsteps, 'Waiting for response');
    debugging("Waiting for response");
    // $airesults = $aiclient->chat($_SESSION[$aicontextkey]['messages']);
    $response = $chatmanager->perform_request(
        $prompt,
        'mod_xaichat',
        $modulecontext->id
    );
    
    $result = $response->get_content();

    $_SESSION[$aicontextkey]['conversation'][] = [
        "role" => \html_writer::tag("strong", $aipic),
        "content" => format_text($result, FORMAT_MARKDOWN)
    ];

    //$progress->update(4, $totalsteps, 'Finished talking to AI');
    $progress->update_full(100,'Finished talking to AI');
    set_debugging(DEBUG_NONE, false);
    // Instead of setting form data, redirect to clear the form input.
    redirect(new \moodle_url('/mod/xaichat/view.php', array('id' => $cm->id)));
} else if ($chatform->is_cancelled()) {
    $_SESSION[$aicontextkey] = [
        'messages'=>[]
    ];
    $_SESSION[$aicontextkey]['messages'] = [];//$aiprovider->generate_system_prompts($cm, $USER);
} else {
    // Clear session on first view of form.
    $toform = [
        'id' => $id,
        'aiproviderid' => $moduleinstance->aiproviderid,
        'aicontext' => $_SESSION[$aicontextkey],
    ];
    // Initialise;
    $chatform->set_data($toform);
}


$displaymessages = isset($_SESSION[$aicontextkey]['conversation']) ? array_reverse($_SESSION[$aicontextkey]['conversation']) : [];
$tcontext = [
    "userpic" => new user_picture($USER),
    "messages" => $displaymessages,
    "rawmessages" => print_r($_SESSION[$aicontextkey]['messages'],1),
];
$chatform->display();

echo $OUTPUT->render_from_template("mod_xaichat/conversation", $tcontext);

echo $OUTPUT->footer();
echo $OUTPUT->footer();
