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

if (!($aiprovider = api::get_provider($moduleinstance->aiproviderid))){
    throw new moodle_exception("noaiproviderfound", 'xaichat');
}
$logger = $aiprovider->get_logger();

$aicontextkey = "mod_xaichat:context:{$cm->id}:{$USER->id}";
if (!isset($_SESSION[$aicontextkey])) {
    $_SESSION[$aicontextkey] = [
        'messages'=> $aiprovider->generate_system_prompts($cm, $USER),
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
$aipic = $aiprovider->get('name');

echo $OUTPUT->header();
//var_dump ($aiprovider->get_settings_for_user($cm, $USER));
$chatform = new aichatform();
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
    $aiclient = new AIClient($aiprovider);

    $progress = new \progress_bar();
    $progress->create();
    if (empty($_SESSION[$aicontextkey]['messages'])) {
        // If the user has not made any prompts yet, we need to prime the interaction with
        // a bunch of system and context specific prompts to constrain behaviour.
        $totalsteps++;
        $progress->update(1, $totalsteps,'Processing System Prompts');
        $logger->info("Processing System Prompts");
        $_SESSION[$aicontextkey]['messages'] = $aiprovider->generate_system_prompts($cm, $USER);
    }
    $progress->update(1, $totalsteps,'Looking for relevant context');
    $logger->info("Looking for relevant context");
    $search = \core_search\manager::instance(true, true);

    // Some of these values can't be "trusted" to the end user to supply, via something
    // like a form, nor can they be entirely left to the plugin developer.
    $settings = $aiprovider->get_settings_for_user($cm, $USER);
    $settings['userquery'] = $data->userprompt;
    // This limits the plugin's search scope.
    $settings['courseids'] = [$course->id];

    $docs = $search->search((object)$settings);

    // Perform "R" from RAG, finding documents from within the context that are similar to the user's prompt.
    // Add the retrieved documents to the context for this chat by generating some system messages with the content
    // returned
    if (empty($docs)) {
        $logger->info("No RAG content returned");
        $prompt = (object)[
            "role" => "user",
            "content" => $data->userprompt
        ];
        $_SESSION[$aicontextkey]['messages'][] = $prompt;
    } else {
        $contextdata = [];
        // Remember We've got a search_engine doc here!
        foreach ($docs as $doc) {
            $strdoc = "Title: {$doc->get('title')}\n";
            $strdoc .= "URL: {$doc->get_doc_url()}\n";
            $strdoc .= $doc->get('content');
            $contextdata[] = $strdoc;
        }
        $context = (object)[
            "role" => "system",
            "iscontext" => true,    // Flag this item as being a context item.
            "content" => "Use the following context to answer following question:" . implode("\n",$contextdata),
        ];
        $_SESSION[$aicontextkey]['messages'][] = $context;
        $prompt = (object)[
            "role" => "user",
            "content" => "$data->userprompt"
        ];
        $_SESSION[$aicontextkey]['messages'][] = $prompt;
        // Render the user's prompt and add it to the "conversation" for display.
        $conversationmessage = clone $prompt;
        $conversationmessage->role = $conversationmessage->role == "user" ? $userpic : \html_writer::tag("strong", $aipic);
        $_SESSION[$aicontextkey]['conversation'][] = $conversationmessage;
    }

    // Pass the whole context over the AI to summarise.    
    $progress->update(3, $totalsteps, 'Waiting for response');
    $logger->info("Waiting for response from {providername}", ["providername" => $aiprovider->get('name')]);
    $airesults = $aiclient->chat($_SESSION[$aicontextkey]['messages']);
    foreach ($airesults as $message) {
        $_SESSION[$aicontextkey]['conversation'][] = [
            "role" => $message->role == "user" ? $userpic : \html_writer::tag("strong", $aipic),
            "content" => format_text($message->content, FORMAT_MARKDOWN)
        ];
    }

    // Truncate the messages.
    $_SESSION[$aicontextkey]['messages'] = array_merge(
        $aiclient->truncate_messages($_SESSION[$aicontextkey]['messages']),
        $airesults
    );
    //$progress->update(4, $totalsteps, 'Finished talking to AI');
    $progress->update_full(100,'Finished talking to AI');
    $logger->info("Finished talking to {providername}", ["providername" => $aiprovider->get('name')]);


} else if ($chatform->is_cancelled()) {
    $_SESSION[$aicontextkey] = [
        'messages'=>[]
    ];
    $_SESSION[$aicontextkey]['messages'] = $aiprovider->generate_system_prompts($cm, $USER);
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

$displaymessages = array_reverse($_SESSION[$aicontextkey]['conversation']);
$tcontext = [
    "userpic" => new user_picture($USER),
    "messages" => $displaymessages,
    "rawmessages" => print_r($_SESSION[$aicontextkey]['messages'],1),
];
$chatform->display();

echo $OUTPUT->render_from_template("mod_xaichat/conversation", $tcontext);

echo $OUTPUT->footer();
