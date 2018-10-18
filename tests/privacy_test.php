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
 * Data provider tests.
 *
 * @package    assignsubmission_gradereviews
 * @category   test
 * @copyright  2018 Church of England
 * @author     Frédéric Massart <fred@branchup.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
global $CFG, $DB;

require_once($CFG->dirroot . '/mod/assign/tests/privacy_test.php');

use core_privacy\tests\provider_testcase;
use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\subsystem_link;
use core_privacy\local\request\contextlist;
// use core_privacy\local\request\approved_contextlist;
// use core_privacy\local\request\transform;
use core_privacy\local\request\writer;
use mod_assign\privacy\assign_plugin_request_data;
use mod_assign\privacy\useridlist;
use assignsubmission_gradereviews\privacy\provider;

/**
 * Data provider testcase class.
 *
 * @package    assignsubmission_gradereviews
 * @category   test
 * @copyright  2018 Church of England
 * @author     Frédéric Massart <fred@branchup.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignsubmission_gradereviews_privacy_testcase extends provider_testcase {

    /**
     * Setup.
     */
    public function setUp() {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Convenience method for creating a submission.
     *
     * Copied from the assignment testcase because extending it caused issues.
     *
     * @param assign $assign The assign object
     * @param stdClass $user The user object
     * @param string $submissiontext Submission text
     * @param integer$attemptnumber The attempt number
     * @return object A submission object.
     */
    protected function create_submission($assign, $user, $submissiontext, $attemptnumber = 0) {
        $submission = $assign->get_user_submission($user->id, true, $attemptnumber);
        $submission->onlinetext_editor = ['text' => $submissiontext,
                                         'format' => FORMAT_MOODLE];

        $this->setUser($user);
        $notices = [];
        $assign->save_submission($submission, $notices);
        return $submission;
    }

    /**
     * Convenience method to create an assignment.
     *
     * Copied from the assignment testcase because extending it caused issues.
     *
     * @param array $params Array of parameters to pass to the generator
     * @return assign The assign class.
     */
    protected function create_instance($params = []) {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $instance = $generator->create_instance($params);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        $context = \context_module::instance($cm->id);
        return new \assign($context, $cm, $params['course']);
    }

    /**
     * Convenience method for creating comments.
     *
     * Note, you must set the current user prior to calling this.
     *
     * @param assign $assign The assignment.
     * @param object $submission The submission.
     * @param string $message The message.
     * @return array With plugin, submission and comment.
     */
    protected function create_comment($assign, $submission, $message) {
        $plugin = $assign->get_submission_plugin_by_type('comments');

        $options = new stdClass();
        $options->area = 'submission_gradereviews';
        $options->course = $assign->get_course();
        $options->context = $assign->get_context();
        $options->itemid = $submission->id;
        $options->component = 'assignsubmission_gradereviews';
        $options->showcount = true;
        $options->displaycancel = true;

        $comment = new comment($options);
        $comment->set_post_permission(true);
        $comment->add($message);

        return $comment;
    }

    /**
     * Test get meta data.
     */
    public function test_get_metadata() {
        $collection = new collection('assignsubmission_gradereviews');
        $collection = provider::get_metadata($collection);
        $items = $collection->get_collection();
        $this->assertCount(1, $items);
        $this->assertTrue($items[0] instanceof subsystem_link);
        $this->assertEquals('core_comment', $items[0]->get_name());
    }

    /**
     * Test get content for user ID.
     */
    public function test_get_context_for_userid_within_submission() {
        $dg = $this->getDataGenerator();

        $c1 = $dg->create_course();
        $c2 = $dg->create_course();

        $u1 = $dg->create_user();
        $u2 = $dg->create_user();
        $u3 = $dg->create_user();
        $u4 = $dg->create_user();
        $u5 = $dg->create_user();

        $dg->enrol_user($u1->id, $c1->id, 'student');
        $dg->enrol_user($u1->id, $c2->id, 'student');
        $dg->enrol_user($u2->id, $c1->id, 'student');
        $dg->enrol_user($u3->id, $c1->id, 'editingteacher');
        $dg->enrol_user($u3->id, $c2->id, 'editingteacher');
        $dg->enrol_user($u4->id, $c1->id, 'editingteacher');
        $dg->enrol_user($u4->id, $c2->id, 'editingteacher');
        $dg->enrol_user($u5->id, $c2->id, 'editingteacher');

        $this->setAdminUser();

        $assign1 = $this->create_instance(['course' => $c1]);
        $assign2 = $this->create_instance(['course' => $c1]);
        $assign3 = $this->create_instance(['course' => $c2]);

        $sub1a = $this->create_submission($assign1, $u1, 'Abc');
        $sub1b = $this->create_submission($assign1, $u2, 'Abc');
        $sub2a = $this->create_submission($assign2, $u1, 'Abc');
        $sub3a = $this->create_submission($assign3, $u1, 'Abc');

        $this->setUser($u3);
        $this->create_comment($assign1, $sub1a, 'Test 1');
        $this->create_comment($assign3, $sub3a, 'Test 2');

        $this->setUser($u4);
        $this->create_comment($assign1, $sub1a, 'Test 3');
        $this->create_comment($assign1, $sub1b, 'Test 4 on u2');

        // User 1 has a submission in each assignment, but no comments.
        $this->setUser($u1);
        $contextlist = new contextlist();
        provider::get_context_for_userid_within_submission($u1->id, $contextlist);
        $contextids = $contextlist->get_contextids();
        $this->assertCount(0, $contextids);

        // User 2 has a submission in one assignment, but no comments.
        $this->setUser($u2);
        $contextlist = new contextlist();
        provider::get_context_for_userid_within_submission($u2->id, $contextlist);
        $contextids = $contextlist->get_contextids();
        $this->assertCount(0, $contextids);

        // User 3 has commented, in two assignments.
        $this->setUser($u3);
        $contextlist = new contextlist();
        provider::get_context_for_userid_within_submission($u3->id, $contextlist);
        $contextids = $contextlist->get_contextids();
        $this->assertCount(2, $contextids);
        $this->assertContains($assign1->get_context()->id, $contextids);
        $this->assertContains($assign3->get_context()->id, $contextids);

        // User 4 has commented, in one assignment.
        $this->setUser($u4);
        $contextlist = new contextlist();
        provider::get_context_for_userid_within_submission($u4->id, $contextlist);
        $contextids = $contextlist->get_contextids();
        $this->assertCount(1, $contextids);
        $this->assertContains($assign1->get_context()->id, $contextids);

        // User 5 did not comment.
        $this->setUser($u5);
        $contextlist = new contextlist();
        provider::get_context_for_userid_within_submission($u5->id, $contextlist);
        $contextids = $contextlist->get_contextids();
        $this->assertCount(0, $contextids);
    }

    /**
     * Test get student user IDs.
     */
    public function test_get_student_user_ids() {
        $dg = $this->getDataGenerator();

        $c1 = $dg->create_course();
        $c2 = $dg->create_course();

        $u1 = $dg->create_user();
        $u2 = $dg->create_user();
        $u3 = $dg->create_user();
        $u4 = $dg->create_user();
        $u5 = $dg->create_user();

        $dg->enrol_user($u1->id, $c1->id, 'student');
        $dg->enrol_user($u1->id, $c2->id, 'student');
        $dg->enrol_user($u2->id, $c1->id, 'student');
        $dg->enrol_user($u3->id, $c1->id, 'editingteacher');
        $dg->enrol_user($u3->id, $c2->id, 'editingteacher');
        $dg->enrol_user($u4->id, $c1->id, 'editingteacher');
        $dg->enrol_user($u4->id, $c2->id, 'editingteacher');
        $dg->enrol_user($u5->id, $c2->id, 'editingteacher');

        $this->setAdminUser();

        $assign1 = $this->create_instance(['course' => $c1]);
        $assign2 = $this->create_instance(['course' => $c1]);
        $assign3 = $this->create_instance(['course' => $c2]);

        $sub1a = $this->create_submission($assign1, $u1, 'Abc');
        $sub1b = $this->create_submission($assign1, $u2, 'Abc');
        $sub2a = $this->create_submission($assign2, $u1, 'Abc');
        $sub3a = $this->create_submission($assign3, $u1, 'Abc');

        $this->setUser($u3);
        $this->create_comment($assign1, $sub1a, 'Test 1');
        $this->create_comment($assign3, $sub3a, 'Test 2');

        $this->setUser($u4);
        $this->create_comment($assign1, $sub1a, 'Test 3');
        $this->create_comment($assign1, $sub1b, 'Test 4 on u2');

        // User 1 and 2 are students, and could not comment.
        $this->assert_student_user_ids($assign1, $u1, []);
        $this->assert_student_user_ids($assign2, $u1, []);
        $this->assert_student_user_ids($assign3, $u1, []);
        $this->assert_student_user_ids($assign1, $u2, []);
        $this->assert_student_user_ids($assign2, $u2, []);
        $this->assert_student_user_ids($assign3, $u2, []);

        // User 3 commented for 1 user on two assignments.
        $this->assert_student_user_ids($assign1, $u3, [$u1->id]);
        $this->assert_student_user_ids($assign2, $u3, []);
        $this->assert_student_user_ids($assign3, $u3, [$u1->id]);

        // User 4 commented for 2 users on one assignment.
        $this->assert_student_user_ids($assign1, $u4, [$u1->id, $u2->id]);
        $this->assert_student_user_ids($assign2, $u4, []);
        $this->assert_student_user_ids($assign3, $u4, []);

        // User 5 is a slacker, and did nothing!
        $this->assert_student_user_ids($assign1, $u5, []);
        $this->assert_student_user_ids($assign2, $u5, []);
        $this->assert_student_user_ids($assign3, $u5, []);
    }

    /**
     * Test exporting data.
     */
    public function test_export_submission_user_data() {
        $dg = $this->getDataGenerator();
        $c1 = $dg->create_course();
        $u1 = $dg->create_user();
        $u2 = $dg->create_user();
        $u3 = $dg->create_user();
        $u4 = $dg->create_user();

        $dg->enrol_user($u1->id, $c1->id, 'student');
        $dg->enrol_user($u2->id, $c1->id, 'student');
        $dg->enrol_user($u3->id, $c1->id, 'editingteacher');
        $dg->enrol_user($u4->id, $c1->id, 'editingteacher');

        $this->setAdminUser();

        $assign1 = $this->create_instance(['course' => $c1]);
        $assign2 = $this->create_instance(['course' => $c1]);

        $sub1a = $this->create_submission($assign1, $u1, 'Abc');
        $sub1b = $this->create_submission($assign1, $u2, 'Abc');
        $sub2a = $this->create_submission($assign2, $u1, 'Abc');

        $this->setUser($u3);
        $this->create_comment($assign1, $sub1a, 'Test 1');
        $this->create_comment($assign1, $sub1a, 'Test 1b');
        $this->create_comment($assign2, $sub2a, 'Test 2');

        $this->setUser($u4);
        $this->create_comment($assign1, $sub1a, 'Test 3');
        $this->create_comment($assign1, $sub1b, 'Test 4 on u2');

        // Check export all in context, typically if a user exported their content.
        $this->assert_export_comments($assign1, $sub1a, null, [[$u4, 'Test 3'], [$u3, 'Test 1b'], [$u3, 'Test 1']]);
        $this->assert_export_comments($assign1, $sub1b, null, [[$u4, 'Test 4 on u2']]);
        $this->assert_export_comments($assign2, $sub2a, null, [[$u3, 'Test 2']]);

        // Check export if user 1 was a teacher.
        $this->assert_export_comments($assign1, $sub1a, $u1, []);
        $this->assert_export_comments($assign1, $sub1b, $u1, []);
        $this->assert_export_comments($assign2, $sub2a, $u1, []);

        // Check export if user 2 was a teacher.
        $this->assert_export_comments($assign1, $sub1a, $u2, []);
        $this->assert_export_comments($assign1, $sub1b, $u2, []);
        $this->assert_export_comments($assign2, $sub2a, $u2, []);

        // Check export with user 3 as teacher.
        $this->assert_export_comments($assign1, $sub1a, $u3, [[$u3, 'Test 1b'], [$u3, 'Test 1']]);
        $this->assert_export_comments($assign1, $sub1b, $u3, []);
        $this->assert_export_comments($assign2, $sub2a, $u3, [[$u3, 'Test 2']]);

        // Check export with user 4 as teacher.
        $this->assert_export_comments($assign1, $sub1a, $u4, [[$u4, 'Test 3']]);
        $this->assert_export_comments($assign1, $sub1b, $u4, [[$u4, 'Test 4 on u2']]);
        $this->assert_export_comments($assign2, $sub2a, $u4, []);
    }

    /**
     * Test deleting submission for context.
     */
    public function test_delete_submission_for_context() {
        global $DB;

        $dg = $this->getDataGenerator();
        $c1 = $dg->create_course();
        $u1 = $dg->create_user();
        $u2 = $dg->create_user();
        $u3 = $dg->create_user();
        $u4 = $dg->create_user();

        $dg->enrol_user($u1->id, $c1->id, 'student');
        $dg->enrol_user($u2->id, $c1->id, 'student');
        $dg->enrol_user($u3->id, $c1->id, 'editingteacher');
        $dg->enrol_user($u4->id, $c1->id, 'editingteacher');

        $this->setAdminUser();

        $assign1 = $this->create_instance(['course' => $c1]);
        $assign2 = $this->create_instance(['course' => $c1]);

        $sub1a = $this->create_submission($assign1, $u1, 'Abc');
        $sub1b = $this->create_submission($assign1, $u2, 'Abc');
        $sub2a = $this->create_submission($assign2, $u1, 'Abc');

        $this->setUser($u3);
        $this->create_comment($assign1, $sub1a, 'Test 1');
        $this->create_comment($assign1, $sub1a, 'Test 1b');
        $this->create_comment($assign2, $sub2a, 'Test 2');

        $this->setUser($u4);
        $this->create_comment($assign1, $sub1a, 'Test 3');
        $this->create_comment($assign1, $sub1b, 'Test 4 on u2');

        $this->assertEquals(4, $DB->count_records('comments', ['contextid' => $assign1->get_context()->id]));
        $this->assertEquals(1, $DB->count_records('comments', ['contextid' => $assign2->get_context()->id]));

        $this->setGuestUser();
        $requestdata = new assign_plugin_request_data($assign1->get_context(), $assign1);
        provider::delete_submission_for_context($requestdata);
        $this->assertEquals(0, $DB->count_records('comments', ['contextid' => $assign1->get_context()->id]));
        $this->assertEquals(1, $DB->count_records('comments', ['contextid' => $assign2->get_context()->id]));
    }

    /**
     * Test deleting submission for user ID.
     */
    public function test_delete_submission_for_userid() {
        global $DB;

        $dg = $this->getDataGenerator();
        $c1 = $dg->create_course();
        $u1 = $dg->create_user();
        $u2 = $dg->create_user();
        $u3 = $dg->create_user();
        $u4 = $dg->create_user();

        $dg->enrol_user($u1->id, $c1->id, 'student');
        $dg->enrol_user($u2->id, $c1->id, 'student');
        $dg->enrol_user($u3->id, $c1->id, 'editingteacher');
        $dg->enrol_user($u4->id, $c1->id, 'editingteacher');

        $this->setAdminUser();

        $assign1 = $this->create_instance(['course' => $c1]);
        $assign2 = $this->create_instance(['course' => $c1]);
        $a1ctx = $assign1->get_context();
        $a2ctx = $assign2->get_context();

        $sub1a = $this->create_submission($assign1, $u1, 'Abc');
        $sub1b = $this->create_submission($assign1, $u2, 'Abc');
        $sub2a = $this->create_submission($assign2, $u1, 'Abc');

        $this->setUser($u3);
        $this->create_comment($assign1, $sub1a, 'Test 1');
        $this->create_comment($assign1, $sub1a, 'Test 1b');
        $this->create_comment($assign2, $sub2a, 'Test 2');

        $this->setUser($u4);
        $this->create_comment($assign1, $sub1a, 'Test 3');
        $this->create_comment($assign1, $sub1b, 'Test 4 on u2');

        $this->assertEquals(3, $DB->count_records('comments', ['contextid' => $a1ctx->id, 'itemid' => $sub1a->id]));
        $this->assertEquals(1, $DB->count_records('comments', ['contextid' => $a1ctx->id, 'itemid' => $sub1b->id]));
        $this->assertEquals(1, $DB->count_records('comments', ['contextid' => $a2ctx->id, 'itemid' => $sub2a->id]));

        $this->setGuestUser();

        $requestdata = new assign_plugin_request_data($a1ctx, $assign1, $sub1a);
        provider::delete_submission_for_userid($requestdata);
        $this->assertEquals(0, $DB->count_records('comments', ['contextid' => $a1ctx->id, 'itemid' => $sub1a->id]));
        $this->assertEquals(1, $DB->count_records('comments', ['contextid' => $a1ctx->id, 'itemid' => $sub1b->id]));
        $this->assertEquals(1, $DB->count_records('comments', ['contextid' => $a2ctx->id, 'itemid' => $sub2a->id]));

        $requestdata = new assign_plugin_request_data($a2ctx, $assign2, $sub2a);
        provider::delete_submission_for_userid($requestdata);
        $this->assertEquals(0, $DB->count_records('comments', ['contextid' => $a1ctx->id, 'itemid' => $sub1a->id]));
        $this->assertEquals(1, $DB->count_records('comments', ['contextid' => $a1ctx->id, 'itemid' => $sub1b->id]));
        $this->assertEquals(0, $DB->count_records('comments', ['contextid' => $a2ctx->id, 'itemid' => $sub2a->id]));
    }

    /**
     * Assert the exported comments.
     *
     * @param object $assign The assignment.
     * @param object $submission The submission.
     * @param object|null $teacher The teacher to export for, e.g. the reviewer.
     * @param bool $asteacher Whether we export as a teacher.
     * @param array $expected Contains [[$user, 'Comment made'], ...]
     */
    protected function assert_export_comments($assign, $submission, $teacher, $expected) {
        if (!empty($teacher)) {
            // We need to ensure that the current user is the teacher.
            $this->setUser($teacher);
        } else {
            // It shouldn't matter which user we are, but just to make sure we set the guest one.
            $this->setGuestUser();
        }
        writer::reset();

        $context = $assign->get_context();
        $requestdata = new assign_plugin_request_data($context, $assign, $submission, [], $teacher ? $teacher : null);
        provider::export_submission_user_data($requestdata);
        $stuff = writer::with_context($context)->get_data([get_string('commentsubcontext', 'core_comment')]);
        $comments = !empty($stuff) ? $stuff->comments : [];

        $this->assertCount(count($expected), $comments);

        // The order of the comments is random.
        foreach ($comments as $i => $comment) {
            $found = false;
            foreach ($expected as $key => $data) {
                $found = $comment->userid == $data[0]->id && strip_tags($comment->content) == $data[1];
                if ($found) {
                    unset($expected[$key]);
                    break;
                }
            }
            $this->assertTrue($found);
        }
        $this->assertEmpty($expected);
    }

    /**
     * Convenience method to assert the result of 'get_user_student_ids'.
     *
     * @param object $assign The assignment.
     * @param object $user The user.
     * @param array $expectedids The expected IDs.
     */
    protected function assert_student_user_ids($assign, $user, $expectedids) {
        $this->setUser($user);

        $useridlist = new useridlist($user->id, $assign->get_instance()->id);
        provider::get_student_user_ids($useridlist);
        $userids = $useridlist->get_userids();

        $this->assertCount(count($expectedids), $userids);
        foreach ($expectedids as $id) {
            $this->assertContains($id, $expectedids);
        }
    }

}
