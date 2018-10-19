<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Privacy API.
 *
 * @package    assignsubmission_gradereviews
 * @copyright  2018 Church of England
 * @author     Frédéric Massart <fred@branchup.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignsubmission_gradereviews\privacy;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assign/locallib.php');

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\contextlist;
use core_comment\privacy\provider as comments_provider;
use mod_assign\privacy\assign_plugin_request_data;
use mod_assign\privacy\assignsubmission_provider;

/**
 * Privacy API class.
 *
 * @package    assignsubmission_gradereviews
 * @copyright  2018 Church of England
 * @author     Frédéric Massart <fred@branchup.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements metadata_provider, assignsubmission_provider {

    use \core_privacy\local\legacy_polyfill;
    use \mod_assign\privacy\submission_legacy_polyfill;

    /**
     * Get the meta data.
     *
     * @param  collection $collection A list of information to add to.
     * @return collection Return the collection after adding to it.
     */
    public static function _get_metadata(collection $collection) {
        $collection->link_subsystem('core_comment', 'privacy:metadata:commentpurpose');
        return $collection;
    }

    /**
     * Get comments.
     *
     * @param  int $userid The user ID that we are finding contexts for.
     * @param  contextlist $contextlist A context list to add sql and params to for contexts.
     */
    public static function _get_context_for_userid_within_submission($userid, contextlist $contextlist) {

        // Add contexts where the author of the comment is the target.
        $sql = "SELECT DISTINCT c.contextid
                  FROM {comments} c
                 WHERE c.userid = :userid
                   AND c.component = :component";
        $params = [
            'userid' => $userid,
            'component' => 'assignsubmission_gradereviews',
        ];
        $contextlist->add_from_sql($sql, $params);

        // No need to add the contexts where the student is the person commented about, because
        // students must have a submisison for comments to be enabled, and submissions context
        // are already taken care of by the assign provider.
    }

    /**
     * Get the student user IDs from the reviewer.
     *
     * @param  \mod_assign\privacy\useridlist $useridlist Resolved the user IDs of students.
     */
    public static function _get_student_user_ids(\mod_assign\privacy\useridlist $useridlist) {
        $sql = "SELECT DISTINCT asub.userid AS id
                  FROM {assign_submission} asub
                  JOIN {comments} c
                    ON c.itemid = asub.id
                   AND c.component = :component
                   AND c.commentarea = :commentarea
                 WHERE c.userid = :teacherid
                   AND asub.assignment = :assignid";
        $params = [
            'assignid' => $useridlist->get_assignid(),
            'teacherid' => $useridlist->get_teacherid(),
            'component' => 'assignsubmission_gradereviews',
            'commentarea' => 'submission_gradereviews'
        ];
        $useridlist->add_from_sql($sql, $params);
    }

    /**
     * Export all user data.
     *
     * @param assign_plugin_request_data $requestdata Request data info.
     */
    public static function _export_submission_user_data(assign_plugin_request_data $requestdata) {
        $component = 'assignsubmission_gradereviews';
        $commentarea = 'submission_gradereviews';
        $submission = $requestdata->get_pluginobject();

        // When a user is passed, that is because we're exporting the reviewer's data, and in this
        // case we will only export the comments made by this person. If the user isn't provided,
        // we need to export all comments made about the submission, because it was requested by
        // the author of the submission.
        $onlyforuser = $requestdata->get_user() !== null;

        comments_provider::export_comments(
            $requestdata->get_context(),
            $component,
            $commentarea,
            $submission->id,
            $requestdata->get_subcontext(),
            $onlyforuser
        );
    }

    /**
     * Delete all comments.
     *
     * @param assign_plugin_request_data $requestdata Request data info.
     */
    public static function _delete_submission_for_context(assign_plugin_request_data $requestdata) {
        comments_provider::delete_comments_for_all_users(
            $requestdata->get_context(),
            'assignsubmission_gradereviews',
            'submission_gradereviews'
        );
    }

    /**
     * Delete user data associated with the user and submission.
     *
     * @param assign_plugin_request_data $requestdata Request data info.
     */
    public static function _delete_submission_for_userid(assign_plugin_request_data $requestdata) {
        $submission = $requestdata->get_pluginobject();
        comments_provider::delete_comments_for_all_users_select(
            $requestdata->get_context(),
            'assignsubmission_gradereviews',
            'submission_gradereviews',
            ' = :itemid',
            ['itemid' => $submission->id]
        );
    }
}
