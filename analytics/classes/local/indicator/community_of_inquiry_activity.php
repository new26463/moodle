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
 * Community of inquiry abstract indicator.
 *
 * @package   core_analytics
 * @copyright 2017 David Monllao {@link http://www.davidmonllao.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core_analytics\local\indicator;

defined('MOODLE_INTERNAL') || die();

/**
 * Community of inquire abstract indicator.
 *
 * @package   core_analytics
 * @copyright 2017 David Monllao {@link http://www.davidmonllao.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class community_of_inquiry_activity extends linear {

    /**
     * @var \core_analytics\course
     */
    protected $course = null;

    /**
     * @var array Array of logs by [contextid][userid]
     */
    protected $activitylogs = null;

    /**
     * @var array Array of grades by [contextid][userid]
     */
    protected $grades = null;

    /**
     * Constant cognitive indicator type.
     */
    const INDICATOR_COGNITIVE = "cognitve";

    /**
     * Constant social indicator type.
     */
    const INDICATOR_SOCIAL = "social";

    /**
     * Max cognitive depth level accepted.
     */
    const MAX_COGNITIVE_LEVEL = 5;

    /**
     * Max social breadth level accepted.
     */
    const MAX_SOCIAL_LEVEL = 5;

    /**
     * Fetch the course grades of this activity type instances.
     *
     * @param \core_analytics\analysable $analysable
     * @return void
     */
    public function fill_per_analysable_caches(\core_analytics\analysable $analysable) {

        // Better to check it, we can not be 100% it will be a \core_analytics\course object.
        if ($analysable instanceof \core_analytics\course) {
            $this->fetch_student_grades($analysable);
        }
    }

    /**
     * Returns the activity type. No point in changing this class in children classes.
     *
     * @var string The activity name (e.g. assign or quiz)
     */
    public final function get_activity_type() {
        $class = get_class($this);
        $package = stristr($class, "\\", true);
        $type = str_replace("mod_", "", $package);
        if ($type === $package) {
            throw new \coding_exception("$class does not belong to any module specific namespace");
        }
        return $type;
    }

    /**
     * Returns the potential level of cognitive depth.
     *
     * @param \cm_info $cm
     * @return int
     */
    public function get_cognitive_depth_level(\cm_info $cm) {
        throw new \coding_exception('Overwrite get_cognitive_depth_level method to set your activity potential cognitive ' .
            'depth level');
    }

    /**
     * Returns the potential level of social breadth.
     *
     * @param \cm_info $cm
     * @return int
     */
    public function get_social_breadth_level(\cm_info $cm) {
        throw new \coding_exception('Overwrite get_social_breadth_level method to set your activity potential social ' .
            'breadth level');
    }

    /**
     * required_sample_data
     *
     * @return string[]
     */
    public static function required_sample_data() {
        // Only course because the indicator is valid even without students.
        return array('course');
    }

    /**
     * Do activity logs contain any log of user in this context?
     *
     * If user is empty we look for any log in this context.
     *
     * @param int $contextid
     * @param \stdClass|false $user
     * @return bool
     */
    protected final function any_log($contextid, $user) {
        if (empty($this->activitylogs[$contextid])) {
            return false;
        }

        // Someone interacted with the activity if there is no user or the user interacted with the
        // activity if there is a user.
        if (empty($user) ||
                (!empty($user) && !empty($this->activitylogs[$contextid][$user->id]))) {
            return true;
        }

        return false;
    }

    /**
     * Do activity logs contain any write log of user in this context?
     *
     * If user is empty we look for any write log in this context.
     *
     * @param int $contextid
     * @param \stdClass|false $user
     * @return bool
     */
    protected final function any_write_log($contextid, $user) {
        if (empty($this->activitylogs[$contextid])) {
            return false;
        }

        // No specific user, we look at all activity logs.
        $it = $this->activitylogs[$contextid];
        if ($user) {
            if (empty($this->activitylogs[$contextid][$user->id])) {
                return false;
            }
            $it = array($user->id => $this->activitylogs[$contextid][$user->id]);
        }
        foreach ($it as $events) {
            foreach ($events as $log) {
                if ($log->crud === 'c' || $log->crud === 'u') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Is there any feedback activity log for this user in this context?
     *
     * This method returns true if $user is empty and there is any feedback activity logs.
     *
     * @param string $action
     * @param \cm_info $cm
     * @param int $contextid
     * @param \stdClass|false $user
     * @return bool
     */
    protected function any_feedback($action, \cm_info $cm, $contextid, $user) {

        if (!in_array($action, ['submitted', 'replied', 'viewed'])) {
            throw new \coding_exception('Provided action "' . $action . '" is not valid.');
        }

        if (empty($this->activitylogs[$contextid])) {
            return false;
        }

        if (empty($this->grades[$contextid]) && $this->feedback_check_grades()) {
            // If there are no grades there is no feedback.
            return false;
        }

        $it = $this->activitylogs[$contextid];
        if ($user) {
            if (empty($this->activitylogs[$contextid][$user->id])) {
                return false;
            }
            $it = array($user->id => $this->activitylogs[$contextid][$user->id]);
        }

        foreach ($this->activitylogs[$contextid] as $userid => $events) {
            $methodname = 'feedback_' . $action;
            if ($this->{$methodname}($cm, $contextid, $userid)) {
                return true;
            }
            // If it wasn't viewed try with the next user.
        }
        return false;
    }

    /**
     * $cm is used for this method overrides.
     *
     * This function must be fast.
     *
     * @param \cm_info $cm
     * @param mixed $contextid
     * @param mixed $userid
     * @param int $after Timestamp, defaults to the graded date or false if we don't check the date.
     * @return bool
     */
    protected function feedback_viewed(\cm_info $cm, $contextid, $userid, $after = null) {
        return $this->feedback_post_action($cm, $contextid, $userid, $this->feedback_viewed_events(), $after);
    }

    /**
     * $cm is used for this method overrides.
     *
     * This function must be fast.
     *
     * @param \cm_info $cm
     * @param mixed $contextid
     * @param mixed $userid
     * @param int $after Timestamp, defaults to the graded date or false if we don't check the date.
     * @return bool
     */
    protected function feedback_replied(\cm_info $cm, $contextid, $userid, $after = null) {
        return $this->feedback_post_action($cm, $contextid, $userid, $this->feedback_replied_events(), $after);
    }

    /**
     * $cm is used for this method overrides.
     *
     * This function must be fast.
     *
     * @param \cm_info $cm
     * @param mixed $contextid
     * @param mixed $userid
     * @param int $after Timestamp, defaults to the graded date or false if we don't check the date.
     * @return bool
     */
    protected function feedback_submitted(\cm_info $cm, $contextid, $userid, $after = null) {
        return $this->feedback_post_action($cm, $contextid, $userid, $this->feedback_submitted_events(), $after);
    }

    /**
     * Returns the list of events that involve viewing feedback from other users.
     *
     * @return string[]
     */
    protected function feedback_viewed_events() {
        throw new \coding_exception('Activities with a potential cognitive or social level that include viewing feedback ' .
            'should define "feedback_viewed_events" method or should override feedback_viewed method.');
    }

    /**
     * Returns the list of events that involve replying to feedback from other users.
     *
     * @return string[]
     */
    protected function feedback_replied_events() {
        throw new \coding_exception('Activities with a potential cognitive or social level that include replying to feedback ' .
            'should define "feedback_replied_events" method or should override feedback_replied method.');
    }

    /**
     * Returns the list of events that involve submitting something after receiving feedback from other users.
     *
     * @return string[]
     */
    protected function feedback_submitted_events() {
        throw new \coding_exception('Activities with a potential cognitive or social level that include viewing feedback ' .
            'should define "feedback_submitted_events" method or should override feedback_submitted method.');
    }

    /**
     * Whether this user in this context did any of the provided actions (events)
     *
     * @param \cm_info $cm
     * @param int $contextid
     * @param int $userid
     * @param string[] $eventnames
     * @param int|false $after
     * @return bool
     */
    protected function feedback_post_action(\cm_info $cm, $contextid, $userid, $eventnames, $after = null) {
        if ($after === null) {
            if ($this->feedback_check_grades()) {
                if (!$after = $this->get_graded_date($contextid, $userid)) {
                    return false;
                }
            } else {
                $after = false;
            }
        }

        if (empty($this->activitylogs[$contextid][$userid])) {
            return false;
        }

        foreach ($eventnames as $eventname) {
            if (!$after) {
                if (!empty($this->activitylogs[$contextid][$userid][$eventname])) {
                    // If we don't care about when the feedback has been seen we consider this enough.
                    return true;
                }
            } else {
                if (empty($this->activitylogs[$contextid][$userid][$eventname])) {
                    continue;
                }
                $timestamps = $this->activitylogs[$contextid][$userid][$eventname]->timecreated;
                // Faster to start by the end.
                rsort($timestamps);
                foreach ($timestamps as $timestamp) {
                    if ($timestamp > $after) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Returns the date a user was graded.
     *
     * @param int $contextid
     * @param int $userid
     * @param bool $checkfeedback Check that the student was graded or check that feedback was given
     * @return int|false
     */
    protected function get_graded_date($contextid, $userid, $checkfeedback = false) {
        if (empty($this->grades[$contextid][$userid])) {
            return false;
        }
        foreach ($this->grades[$contextid][$userid] as $gradeitemid => $gradeitem) {

            // We check that either feedback or the grade is set.
            if (($checkfeedback && $gradeitem->feedback) || $gradeitem->grade) {

                // Grab the first graded date.
                if ($gradeitem->dategraded && (empty($after) || $gradeitem->dategraded < $after)) {
                    $after = $gradeitem->dategraded;
                }
            }
        }

        if (!isset($after)) {
            // False if there are no graded items.
            return false;
        }

        return $after;
    }

    /**
     * Returns the activities the user had access to between a time period.
     *
     * @param int $sampleid
     * @param string $tablename
     * @param int $starttime
     * @param int $endtime
     * @return array
     */
    protected function get_student_activities($sampleid, $tablename, $starttime, $endtime) {

        // May not be available.
        $user = $this->retrieve('user', $sampleid);

        if ($this->course === null) {
            // The indicator scope is a range, so all activities belong to the same course.
            $this->course = \core_analytics\course::instance($this->retrieve('course', $sampleid));
        }

        if ($this->activitylogs === null) {
            // Fetch all activity logs in each activity in the course, not restricted to a specific sample so we can cache it.

            $courseactivities = $this->course->get_all_activities($this->get_activity_type());

            // Null if no activities of this type in this course.
            if (empty($courseactivities)) {
                $this->activitylogs = false;
                return null;
            }
            $this->activitylogs = $this->fetch_activity_logs($courseactivities, $starttime, $endtime);
        }

        if ($this->grades === null) {
            // Even if this is probably already filled during fill_per_analysable_caches.
            $this->fetch_student_grades($this->course);
        }

        if ($cm = $this->retrieve('cm', $sampleid)) {
            // Samples are at cm level or below.
            $useractivities = array(\context_module::instance($cm->id)->id => $cm);
        } else {
            // All course activities.
            $useractivities = $this->course->get_activities($this->get_activity_type(), $starttime, $endtime, $user);
        }

        return $useractivities;
    }

    /**
     * Fetch acitivity logs from database
     *
     * @param array $activities
     * @param int $starttime
     * @param int $endtime
     * @return array
     */
    protected function fetch_activity_logs($activities, $starttime = false, $endtime = false) {
        global $DB;

        // Filter by context to use the db table index.
        list($contextsql, $contextparams) = $DB->get_in_or_equal(array_keys($activities), SQL_PARAMS_NAMED);
        $select = "contextid $contextsql AND timecreated > :starttime AND timecreated <= :endtime";
        $params = $contextparams + array('starttime' => $starttime, 'endtime' => $endtime);

        // Pity that we need to pass through logging readers API when most of the people just uses the standard one.
        if (!$logstore = \core_analytics\manager::get_analytics_logstore()) {
            throw new \coding_exception('No log store available');
        }
        $events = $logstore->get_events_select_iterator($select, $params, 'timecreated ASC', 0, 0);

        // Returs the logs organised by contextid, userid and eventname so it is easier to calculate activities data later.
        // At the same time we want to keep this array reasonably "not-massive".
        $processedevents = array();
        foreach ($events as $event) {
            if (!isset($processedevents[$event->contextid])) {
                $processedevents[$event->contextid] = array();
            }
            if (!isset($processedevents[$event->contextid][$event->userid])) {
                $processedevents[$event->contextid][$event->userid] = array();
            }

            // Contextid and userid have already been used to index the events, the next field to index by is eventname:
            // crud is unique per eventname, courseid is the same for all records and we append timecreated.
            if (!isset($processedevents[$event->contextid][$event->userid][$event->eventname])) {

                // Remove all data that can change between events of the same type.
                $data = (object)$event->get_data();
                unset($data->id);
                unset($data->anonymous);
                unset($data->relateduserid);
                unset($data->other);
                unset($data->origin);
                unset($data->ip);
                $processedevents[$event->contextid][$event->userid][$event->eventname] = $data;
                // We want timecreated attribute to be an array containing all user access times.
                $processedevents[$event->contextid][$event->userid][$event->eventname]->timecreated = array();
            }

            // Add the event timecreated.
            $processedevents[$event->contextid][$event->userid][$event->eventname]->timecreated[] = intval($event->timecreated);
        }
        $events->close();

        return $processedevents;
    }

    /**
     * Whether grades should be checked or not when looking for feedback.
     *
     * @return bool
     */
    protected function feedback_check_grades() {
        return true;
    }

    /**
     * Calculates the cognitive depth of a sample.
     *
     * @param int $sampleid
     * @param string $tablename
     * @param int $starttime
     * @param int $endtime
     * @return float|int|null
     * @throws \coding_exception
     */
    protected function cognitive_calculate_sample($sampleid, $tablename, $starttime = false, $endtime = false) {

        // May not be available.
        $user = $this->retrieve('user', $sampleid);

        if (!$useractivities = $this->get_student_activities($sampleid, $tablename, $starttime, $endtime)) {
            // Null if no activities.
            return null;
        }

        $scoreperactivity = (self::get_max_value() - self::get_min_value()) / count($useractivities);

        $score = self::get_min_value();

        // Iterate through the module activities/resources which due date is part of this time range.
        foreach ($useractivities as $contextid => $cm) {

            $potentiallevel = $this->get_cognitive_depth_level($cm);
            if (!is_int($potentiallevel) || $potentiallevel > self::MAX_COGNITIVE_LEVEL || $potentiallevel < 1) {
                throw new \coding_exception('Activities\' potential cognitive depth go from 1 to 5.');
            }
            $scoreperlevel = $scoreperactivity / $potentiallevel;

            switch ($potentiallevel) {
                case 5:
                    // Cognitive level 5 is to submit after feedback.
                    if ($this->any_feedback('submitted', $cm, $contextid, $user)) {
                        $score += $scoreperlevel * 5;
                        break;
                    }
                    // The user didn't reach the activity max cognitive depth, continue with level 2.

                case 4:
                    // Cognitive level 4 is to comment on feedback.
                    if ($this->any_feedback('replied', $cm, $contextid, $user)) {
                        $score += $scoreperlevel * 4;
                        break;
                    }
                    // The user didn't reach the activity max cognitive depth, continue with level 2.

                case 3:
                    // Cognitive level 3 is to view feedback.

                    if ($this->any_feedback('viewed', $cm, $contextid, $user)) {
                        // Max score for level 3.
                        $score += $scoreperlevel * 3;
                        break;
                    }
                    // The user didn't reach the activity max cognitive depth, continue with level 2.

                case 2:
                    // Cognitive depth level 2 is to submit content.

                    if ($this->any_write_log($contextid, $user)) {
                        $score += $scoreperlevel * 2;
                        break;
                    }
                    // The user didn't reach the activity max cognitive depth, continue with level 1.

                case 1:
                    // Cognitive depth level 1 is just accessing the activity.

                    if ($this->any_log($contextid, $user)) {
                        $score += $scoreperlevel;
                    }

                default:
            }
        }

        // To avoid decimal problems.
        if ($score > self::MAX_VALUE) {
            return self::MAX_VALUE;
        } else if ($score < self::MIN_VALUE) {
            return self::MIN_VALUE;
        }
        return $score;
    }

    /**
     * Calculates the social breadth of a sample.
     *
     * @param int $sampleid
     * @param string $tablename
     * @param int $starttime
     * @param int $endtime
     * @return float|int|null
     */
    protected function social_calculate_sample($sampleid, $tablename, $starttime = false, $endtime = false) {

        // May not be available.
        $user = $this->retrieve('user', $sampleid);

        if (!$useractivities = $this->get_student_activities($sampleid, $tablename, $starttime, $endtime)) {
            // Null if no activities.
            return null;
        }

        $scoreperactivity = (self::get_max_value() - self::get_min_value()) / count($useractivities);

        $score = self::get_min_value();

        foreach ($useractivities as $contextid => $cm) {

            $potentiallevel = $this->get_social_breadth_level($cm);
            if (!is_int($potentiallevel) || $potentiallevel > self::MAX_SOCIAL_LEVEL || $potentiallevel < 1) {
                throw new \coding_exception('Activities\' potential social breadth go from 1 to ' .
                    community_of_inquiry_activity::MAX_SOCIAL_LEVEL . '.');
            }
            $scoreperlevel = $scoreperactivity / $potentiallevel;
            switch ($potentiallevel) {
                case 2:
                case 3:
                case 4:
                case 5:
                    // Core activities social breadth only reaches level 2, until core activities social
                    // breadth do not reach level 5 we limit it to what we currently support, which is level 2.

                    // Social breadth level 2 is to view feedback. (Same as cognitive level 3).

                    if ($this->any_feedback('viewed', $cm, $contextid, $user)) {
                        // Max score for level 2.
                        $score += $scoreperlevel * 2;
                        break;
                    }
                    // The user didn't reach the activity max social breadth, continue with level 1.

                case 1:
                    // Social breadth level 1 is just accessing the activity.
                    if ($this->any_log($contextid, $user)) {
                        $score += $scoreperlevel;
                    }
            }

        }

        // To avoid decimal problems.
        if ($score > self::MAX_VALUE) {
            return self::MAX_VALUE;
        } else if ($score < self::MIN_VALUE) {
            return self::MIN_VALUE;
        }
        return $score;
    }

    /**
     * calculate_sample
     *
     * @throws \coding_exception
     * @param int $sampleid
     * @param string $tablename
     * @param int $starttime
     * @param int $endtime
     * @return float|int|null
     */
    protected function calculate_sample($sampleid, $tablename, $starttime = false, $endtime = false) {
        if ($this->get_indicator_type() == self::INDICATOR_COGNITIVE) {
            return $this->cognitive_calculate_sample($sampleid, $tablename, $starttime, $endtime);
        } else if ($this->get_indicator_type() == self::INDICATOR_SOCIAL) {
            return $this->social_calculate_sample($sampleid, $tablename, $starttime, $endtime);
        }
        throw new \coding_exception("Indicator type is invalid.");
    }

    /**
     * Gets the course student grades.
     *
     * @param \core_analytics\course $course
     * @return void
     */
    protected function fetch_student_grades(\core_analytics\course $course) {
        $courseactivities = $course->get_all_activities($this->get_activity_type());
        $this->grades = $course->get_student_grades($courseactivities);
    }

    /**
     * Defines indicator type.
     *
     * @return string
     */
    abstract public function get_indicator_type();
}