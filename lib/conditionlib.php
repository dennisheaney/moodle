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
 * Used for tracking conditions that apply before activities are displayed
 * to students ('conditional availability').
 *
 * @package    core_condition
 * @category   condition
 * @copyright  1999 onwards Martin Dougiamas  http://dougiamas.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * CONDITION_STUDENTVIEW_HIDE - The activity is not displayed to students at all when conditions aren't met.
 */
define('CONDITION_STUDENTVIEW_HIDE',0);
/**
 * CONDITION_STUDENTVIEW_SHOW - The activity is displayed to students as a greyed-out name, with
 * informational text that explains the conditions under which it will be available.
 */
define('CONDITION_STUDENTVIEW_SHOW',1);

/**
 * CONDITION_MISSING_NOTHING - The $cm variable is expected to contain all completion-related data
 */
define('CONDITION_MISSING_NOTHING',0);
/**
 * CONDITION_MISSING_EXTRATABLE - The $cm variable is expected to contain the fields from course_modules
 * but not the course_modules_availability data
 */
define('CONDITION_MISSING_EXTRATABLE',1);
/**
 * CONDITION_MISSING_EVERYTHING - The $cm variable is expected to contain nothing except the ID
 */
define('CONDITION_MISSING_EVERYTHING',2);

require_once($CFG->libdir.'/completionlib.php');

/**
 * @global stdClass $CONDITIONLIB_PRIVATE
 * @name $CONDITIONLIB_PRIVATE
 */
global $CONDITIONLIB_PRIVATE;
$CONDITIONLIB_PRIVATE = new stdClass;
// Caches whether completion values are used in availability conditions.
// Array of course => array of cmid => true.
$CONDITIONLIB_PRIVATE->usedincondition = array();

/**
 * Core class to handle conditional activites
 *
 * @package   core_condition
 * @category  condition
 * @copyright 2008 Sam Marshall
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class condition_info {
    /** @var object bool */
    private $cm, $gotdata;

    /**
     * Constructs with course-module details.
     *
     * @global moodle_database $DB
     * @uses CONDITION_MISSING_NOTHING
     * @uses CONDITION_MISSING_EVERYTHING
     * @uses DEBUG_DEVELOPER
     * @uses CONDITION_MISSING_EXTRATABLE
     * @param object $cm Moodle course-module object. May have extra fields
     *   ->conditionsgrade, ->conditionscompletion which should come from
     *   get_fast_modinfo. Should have ->availablefrom, ->availableuntil,
     *   and ->showavailability, ->course; but the only required thing is ->id.
     * @param int $expectingmissing Used to control whether or not a developer
     *   debugging message (performance warning) will be displayed if some of
     *   the above data is missing and needs to be retrieved; a
     *   CONDITION_MISSING_xx constant
     * @param bool $loaddata If you need a 'write-only' object, set this value
     *   to false to prevent database access from constructor
     * @return condition_info Object which can retrieve information about the
     *   activity
     */
    public function __construct($cm, $expectingmissing=CONDITION_MISSING_NOTHING,
        $loaddata=true) {
        global $DB;

        // Check ID as otherwise we can't do the other queries
        if (empty($cm->id)) {
            throw new coding_exception("Invalid parameters; course-module ID not included");
        }

        // If not loading data, don't do anything else
        if (!$loaddata) {
            $this->cm = (object)array('id'=>$cm->id);
            $this->gotdata = false;
            return;
        }

        // Missing basic data from course_modules
        if (!isset($cm->availablefrom) || !isset($cm->availableuntil) ||
            !isset($cm->showavailability) || !isset($cm->course)) {
            if ($expectingmissing<CONDITION_MISSING_EVERYTHING) {
                debugging('Performance warning: condition_info constructor is
                    faster if you pass in $cm with at least basic fields
                    (availablefrom,availableuntil,showavailability,course).
                    [This warning can be disabled, see phpdoc.]',
                    DEBUG_DEVELOPER);
            }
            $cm = $DB->get_record('course_modules',array('id'=>$cm->id),
                'id,course,availablefrom,availableuntil,showavailability');
        }

        $this->cm = clone($cm);
        $this->gotdata = true;

        // Missing extra data
        if (!isset($cm->conditionsgrade) || !isset($cm->conditionscompletion)) {
            if ($expectingmissing<CONDITION_MISSING_EXTRATABLE) {
                debugging('Performance warning: condition_info constructor is
                    faster if you pass in a $cm from get_fast_modinfo.
                    [This warning can be disabled, see phpdoc.]',
                    DEBUG_DEVELOPER);
            }

            self::fill_availability_conditions($this->cm);
        }
    }

    /**
     * Adds the extra availability conditions (if any) into the given
     * course-module object.
     *
     * @global moodle_database $DB
     * @global object $CFG
     * @param object $cm Moodle course-module data object
     */
    public static function fill_availability_conditions(&$cm) {
        if (empty($cm->id)) {
            throw new coding_exception("Invalid parameters; course-module ID not included");
        }

        // Does nothing if the variables are already present
        if (!isset($cm->conditionsgrade) ||
            !isset($cm->conditionscompletion)) {
            $cm->conditionsgrade=array();
            $cm->conditionscompletion=array();

            global $DB, $CFG;
            $conditions = $DB->get_records_sql($sql="
SELECT
    cma.id as cmaid, gi.*,cma.sourcecmid,cma.requiredcompletion,cma.gradeitemid,
    cma.grademin as conditiongrademin, cma.grademax as conditiongrademax
FROM
    {course_modules_availability} cma
    LEFT JOIN {grade_items} gi ON gi.id=cma.gradeitemid
WHERE
    coursemoduleid=?",array($cm->id));
            foreach ($conditions as $condition) {
                if (!is_null($condition->sourcecmid)) {
                    $cm->conditionscompletion[$condition->sourcecmid] =
                        $condition->requiredcompletion;
                } else {
                    $minmax = new stdClass;
                    $minmax->min = $condition->conditiongrademin;
                    $minmax->max = $condition->conditiongrademax;
                    $minmax->name = self::get_grade_name($condition);
                    $cm->conditionsgrade[$condition->gradeitemid] = $minmax;
                }
            }
        }
    }

    /**
     * Obtains the name of a grade item.
     *
     * @global moodle_database $DB
     * @param object $gradeitemobj Object from get_record on grade_items table,
     *     (can be empty if you want to just get !missing)
     * @return string Name of item of !missing if it didn't exist
     */
    private static function get_grade_name($gradeitemobj) {
        global $CFG;
        if (isset($gradeitemobj->id)) {
            require_once($CFG->libdir.'/gradelib.php');
            $item = new grade_item;
            grade_object::set_properties($item, $gradeitemobj);
            return $item->get_name();
        } else {
            return '!missing'; // Ooops, missing grade
        }
    }

    /**
     * Just a wrapper to call require_data()
     *
     * @see require_data()
     * @return object A course-module object with all the information required to
     *   determine availability.
     */
    public function get_full_course_module() {
        $this->require_data();
        return $this->cm;
    }

    /**
     * Adds to the database a condition based on completion of another module.
     *
     * @global moodle_database $DB
     * @param int $cmid ID of other module
     * @param int $requiredcompletion COMPLETION_xx constant
     */
    public function add_completion_condition($cmid, $requiredcompletion) {
        // Add to DB
        global $DB;
        $DB->insert_record('course_modules_availability',
            (object)array('coursemoduleid'=>$this->cm->id,
                'sourcecmid'=>$cmid, 'requiredcompletion'=>$requiredcompletion),
            false);

        // Store in memory too
        $this->cm->conditionscompletion[$cmid] = $requiredcompletion;
    }

    /**
     * Adds to the database a condition based on the value of a grade item.
     *
     * @global moodle_database $DB
     * @param int $gradeitemid ID of grade item
     * @param float $min Minimum grade (>=), up to 5 decimal points, or null if none
     * @param float $max Maximum grade (<), up to 5 decimal points, or null if none
     * @param bool $updateinmemory If true, updates data in memory; otherwise,
     *   memory version may be out of date (this has performance consequences,
     *   so don't do it unless it really needs updating)
     */
    public function add_grade_condition($gradeitemid, $min, $max, $updateinmemory=false) {
        // Normalise nulls
        if ($min==='') {
            $min = null;
        }
        if ($max==='') {
            $max = null;
        }
        // Add to DB
        global $DB;
        $DB->insert_record('course_modules_availability',
            (object)array('coursemoduleid'=>$this->cm->id,
                'gradeitemid'=>$gradeitemid, 'grademin'=>$min, 'grademax'=>$max),
            false);

        // Store in memory too
        if ($updateinmemory) {
            $this->cm->conditionsgrade[$gradeitemid]=(object)array(
                'min'=>$min, 'max'=>$max);
            $this->cm->conditionsgrade[$gradeitemid]->name =
                self::get_grade_name($DB->get_record('grade_items',
                    array('id'=>$gradeitemid)));
        }
    }

    /**
     * Erases from the database all conditions for this activity.
     *
     * @global moodle_database $DB
     */
    public function wipe_conditions() {
        // Wipe from DB
        global $DB;
        $DB->delete_records('course_modules_availability',
            array('coursemoduleid'=>$this->cm->id));

        // And from memory
        $this->cm->conditionsgrade = array();
        $this->cm->conditionscompletion = array();
    }

    /**
     * Obtains a string describing all availability restrictions (even if
     * they do not apply any more).
     *
     * @global stdClass $COURSE
     * @global moodle_database $DB
     * @param object $modinfo Usually leave as null for default. Specify when
     *   calling recursively from inside get_fast_modinfo. The value supplied
     *   here must include list of all CMs with 'id' and 'name'
     * @return string Information string (for admin) about all restrictions on
     *   this item
     */
    public function get_full_information($modinfo=null) {
        $this->require_data();
        global $COURSE, $DB;

        $information = '';

        // Completion conditions
        if(count($this->cm->conditionscompletion)>0) {
            if ($this->cm->course==$COURSE->id) {
                $course = $COURSE;
            } else {
                $course = $DB->get_record('course',array('id'=>$this->cm->course),'id,enablecompletion,modinfo');
            }
            foreach ($this->cm->conditionscompletion as $cmid=>$expectedcompletion) {
                if (!$modinfo) {
                    $modinfo = get_fast_modinfo($course);
                }
                if (empty($modinfo->cms[$cmid])) {
                    continue;
                }
                $information .= get_string(
                    'requires_completion_'.$expectedcompletion,
                    'condition', $modinfo->cms[$cmid]->name).' ';
            }
        }

        // Grade conditions
        if (count($this->cm->conditionsgrade)>0) {
            foreach ($this->cm->conditionsgrade as $gradeitemid=>$minmax) {
                // String depends on type of requirement. We are coy about
                // the actual numbers, in case grades aren't released to
                // students.
                if (is_null($minmax->min) && is_null($minmax->max)) {
                    $string = 'any';
                } else if (is_null($minmax->max)) {
                    $string = 'min';
                } else if (is_null($minmax->min)) {
                    $string = 'max';
                } else {
                    $string = 'range';
                }
                $information .= get_string('requires_grade_'.$string, 'condition', $minmax->name).' ';
            }
        }

        // The date logic is complicated. The intention of this logic is:
        // 1) display date without time where possible (whenever the date is
        //    midnight)
        // 2) when the 'until' date is e.g. 00:00 on the 14th, we display it as
        //    'until the 13th' (experience at the OU showed that students are
        //    likely to interpret 'until <date>' as 'until the end of <date>').
        // 3) This behaviour becomes confusing for 'same-day' dates where there
        //    are some exceptions.
        // Users in different time zones will typically not get the 'abbreviated'
        // behaviour but it should work OK for them aside from that.

        // The following cases are possible:
        // a) From 13:05 on 14 Oct until 12:10 on 17 Oct (exact, exact)
        // b) From 14 Oct until 12:11 on 17 Oct (midnight, exact)
        // c) From 13:05 on 14 Oct until 17 Oct (exact, midnight 18 Oct)
        // d) From 14 Oct until 17 Oct (midnight 14 Oct, midnight 18 Oct)
        // e) On 14 Oct (midnight 14 Oct, midnight 15 Oct)
        // f) From 13:05 on 14 Oct until 0:00 on 15 Oct (exact, midnight, same day)
        // g) From 0:00 on 14 Oct until 12:05 on 14 Oct (midnight, exact, same day)
        // h) From 13:05 on 14 Oct (exact)
        // i) From 14 Oct (midnight)
        // j) Until 13:05 on 14 Oct (exact)
        // k) Until 14 Oct (midnight 15 Oct)

        // Check if start and end dates are 'midnights', if so we show in short form
        $shortfrom = self::is_midnight($this->cm->availablefrom);
        $shortuntil = self::is_midnight($this->cm->availableuntil);

        // For some checks and for display, we need the previous day for the 'until'
        // value, if we are going to display it in short form
        if ($this->cm->availableuntil) {
            $daybeforeuntil = strtotime("-1 day", usergetmidnight($this->cm->availableuntil));
        }

        // Special case for if one but not both are exact and they are within a day
        if ($this->cm->availablefrom && $this->cm->availableuntil &&
                $shortfrom != $shortuntil && $daybeforeuntil < $this->cm->availablefrom) {
            // Don't use abbreviated version (see examples f, g above)
            $shortfrom = false;
            $shortuntil = false;
        }

        // When showing short end date, the display time is the 'day before' one
        $displayuntil = $shortuntil ? $daybeforeuntil : $this->cm->availableuntil;

        if ($this->cm->availablefrom && $this->cm->availableuntil) {
            if ($shortfrom && $shortuntil && $daybeforeuntil == $this->cm->availablefrom) {
                $information .= get_string('requires_date_both_single_day', 'condition',
                        self::show_time($this->cm->availablefrom, true));
            } else {
                $information .= get_string('requires_date_both', 'condition', (object)array(
                         'from' => self::show_time($this->cm->availablefrom, $shortfrom),
                         'until' => self::show_time($displayuntil, $shortuntil)));
            }
        } else if ($this->cm->availablefrom) {
            $information .= get_string('requires_date', 'condition',
                self::show_time($this->cm->availablefrom, $shortfrom));
        } else if ($this->cm->availableuntil) {
            $information .= get_string('requires_date_before', 'condition',
                self::show_time($displayuntil, $shortuntil));
        }

        $information = trim($information);
        return $information;
    }

    /**
     * Checks whether a given time refers exactly to midnight (in current user
     * timezone).
     *
     * @param int $time Time
     * @return bool True if time refers to midnight, false if it's some other
     *   time or if it is set to zero
     */
    private static function is_midnight($time) {
        return $time && usergetmidnight($time) == $time;
    }

    /**
     * Determines whether this particular course-module is currently available
     * according to these criteria.
     *
     * - This does not include the 'visible' setting (i.e. this might return
     *   true even if visible is false); visible is handled independently.
     * - This does not take account of the viewhiddenactivities capability.
     *   That should apply later.
     *
     * @global stdClass $COURSE
     * @global moodle_database $DB
     * @uses COMPLETION_COMPLETE
     * @uses COMPLETION_COMPLETE_FAIL
     * @uses COMPLETION_COMPLETE_PASS
     * @param string $information If the item has availability restrictions,
     *   a string that describes the conditions will be stored in this variable;
     *   if this variable is set blank, that means don't display anything
     * @param bool $grabthelot Performance hint: if true, caches information
     *   required for all course-modules, to make the front page and similar
     *   pages work more quickly (works only for current user)
     * @param int $userid If set, specifies a different user ID to check availability for
     * @param object $modinfo Usually leave as null for default. Specify when
     *   calling recursively from inside get_fast_modinfo. The value supplied
     *   here must include list of all CMs with 'id' and 'name'
     * @return bool True if this item is available to the user, false otherwise
     */
    public function is_available(&$information, $grabthelot=false, $userid=0, $modinfo=null) {
        $this->require_data();
        global $COURSE,$DB;

        $available = true;
        $information = '';

        // Check each completion condition
        if(count($this->cm->conditionscompletion)>0) {
            if ($this->cm->course==$COURSE->id) {
                $course = $COURSE;
            } else {
                $course = $DB->get_record('course',array('id'=>$this->cm->course),'id,enablecompletion,modinfo');
            }

            $completion = new completion_info($course);
            foreach ($this->cm->conditionscompletion as $cmid=>$expectedcompletion) {
                // If this depends on a deleted module, handle that situation
                // gracefully.
                if (!$modinfo) {
                    $modinfo = get_fast_modinfo($course);
                }
                if (empty($modinfo->cms[$cmid])) {
                    global $PAGE, $UNITTEST;
                    if (!empty($UNITTEST) || (isset($PAGE) && strpos($PAGE->pagetype, 'course-view-')===0)) {
                        debugging("Warning: activity {$this->cm->id} '{$this->cm->name}' has condition on deleted activity $cmid (to get rid of this message, edit the named activity)");
                    }
                    continue;
                }

                // The completion system caches its own data
                $completiondata = $completion->get_data((object)array('id'=>$cmid),
                    $grabthelot, $userid, $modinfo);

                $thisisok = true;
                if ($expectedcompletion==COMPLETION_COMPLETE) {
                    // 'Complete' also allows the pass, fail states
                    switch ($completiondata->completionstate) {
                        case COMPLETION_COMPLETE:
                        case COMPLETION_COMPLETE_FAIL:
                        case COMPLETION_COMPLETE_PASS:
                            break;
                        default:
                            $thisisok = false;
                    }
                } else {
                    // Other values require exact match
                    if ($completiondata->completionstate!=$expectedcompletion) {
                        $thisisok = false;
                    }
                }
                if (!$thisisok) {
                    $available = false;
                    $information .= get_string(
                        'requires_completion_'.$expectedcompletion,
                        'condition',$modinfo->cms[$cmid]->name).' ';
                }
            }
        }

        // Check each grade condition
        if (count($this->cm->conditionsgrade)>0) {
            foreach ($this->cm->conditionsgrade as $gradeitemid=>$minmax) {
                $score = $this->get_cached_grade_score($gradeitemid, $grabthelot, $userid);
                if ($score===false ||
                    (!is_null($minmax->min) && $score<$minmax->min) ||
                    (!is_null($minmax->max) && $score>=$minmax->max)) {
                    // Grade fail
                    $available = false;
                    // String depends on type of requirement. We are coy about
                    // the actual numbers, in case grades aren't released to
                    // students.
                    if (is_null($minmax->min) && is_null($minmax->max)) {
                        $string = 'any';
                    } else if (is_null($minmax->max)) {
                        $string = 'min';
                    } else if (is_null($minmax->min)) {
                        $string = 'max';
                    } else {
                        $string = 'range';
                    }
                    $information .= get_string('requires_grade_'.$string, 'condition', $minmax->name).' ';
                }
            }
        }

        // Test dates
        if ($this->cm->availablefrom) {
            if (time() < $this->cm->availablefrom) {
                $available = false;

                $information .= get_string('requires_date', 'condition',
                        self::show_time($this->cm->availablefrom,
                            self::is_midnight($this->cm->availablefrom)));
            }
        }

        if ($this->cm->availableuntil) {
            if (time() >= $this->cm->availableuntil) {
                $available = false;
                // But we don't display any information about this case. This is
                // because the only reason to set a 'disappear' date is usually
                // to get rid of outdated information/clutter in which case there
                // is no point in showing it...

                // Note it would be nice if we could make it so that the 'until'
                // date appears below the item while the item is still accessible,
                // unfortunately this is not possible in the current system. Maybe
                // later, or if somebody else wants to add it.
            }
        }

        $information=trim($information);
        return $available;
    }

    /**
     * Shows a time either as a date or a full date and time, according to
     * user's timezone.
     *
     * @param int $time Time
     * @param bool $dateonly If true, uses date only
     * @return string Date
     */
    private function show_time($time, $dateonly) {
        return userdate($time,
                get_string($dateonly ? 'strftimedate' : 'strftimedatetime', 'langconfig'));
    }

    /**
     * This function is used to check if information about availability should be shown to user or not
     *
     * @return bool True if information about availability should be shown to
     *   normal users
     * @throws coding_exception If data wasn't loaded
     */
    public function show_availability() {
        $this->require_data();
        return $this->cm->showavailability;
    }

    /**
     * Internal function cheks that data was loaded.
     *
     * @return void throws coding_exception If data wasn't loaded
     */
    private function require_data() {
        if (!$this->gotdata) {
            throw new coding_exception('Error: cannot call when info was '.
                'constructed without data');
        }
    }

    /**
     * Obtains a grade score. Note that this score should not be displayed to
     * the user, because gradebook rules might prohibit that. It may be a
     * non-final score subject to adjustment later.
     *
     * @global stdClass $USER
     * @global moodle_database $DB
     * @global stdClass $SESSION
     * @param int $gradeitemid Grade item ID we're interested in
     * @param bool $grabthelot If true, grabs all scores for current user on
     *   this course, so that later ones come from cache
     * @param int $userid Set if requesting grade for a different user (does
     *   not use cache)
     * @return float Grade score as a percentage in range 0-100 (e.g. 100.0
     *   or 37.21), or false if user does not have a grade yet
     */
    private function get_cached_grade_score($gradeitemid, $grabthelot=false, $userid=0) {
        global $USER, $DB, $SESSION;
        if ($userid==0 || $userid==$USER->id) {
            // For current user, go via cache in session
            if (empty($SESSION->gradescorecache) || $SESSION->gradescorecacheuserid!=$USER->id) {
                $SESSION->gradescorecache = array();
                $SESSION->gradescorecacheuserid = $USER->id;
            }
            if (!array_key_exists($gradeitemid, $SESSION->gradescorecache)) {
                if ($grabthelot) {
                    // Get all grades for the current course
                    $rs = $DB->get_recordset_sql("
SELECT
    gi.id,gg.finalgrade,gg.rawgrademin,gg.rawgrademax
FROM
    {grade_items} gi
    LEFT JOIN {grade_grades} gg ON gi.id=gg.itemid AND gg.userid=?
WHERE
    gi.courseid=?", array($USER->id, $this->cm->course));
                    foreach ($rs as $record) {
                        $SESSION->gradescorecache[$record->id] =
                            is_null($record->finalgrade)
                                // No grade = false
                                ? false
                                // Otherwise convert grade to percentage
                                : (($record->finalgrade - $record->rawgrademin) * 100) /
                                    ($record->rawgrademax - $record->rawgrademin);

                    }
                    $rs->close();
                    // And if it's still not set, well it doesn't exist (eg
                    // maybe the user set it as a condition, then deleted the
                    // grade item) so we call it false
                    if (!array_key_exists($gradeitemid, $SESSION->gradescorecache)) {
                        $SESSION->gradescorecache[$gradeitemid] = false;
                    }
                } else {
                    // Just get current grade
                    $record = $DB->get_record('grade_grades', array(
                        'userid'=>$USER->id, 'itemid'=>$gradeitemid));
                    if ($record && !is_null($record->finalgrade)) {
                        $score = (($record->finalgrade - $record->rawgrademin) * 100) /
                            ($record->rawgrademax - $record->rawgrademin);
                    } else {
                        // Treat the case where row exists but is null, same as
                        // case where row doesn't exist
                        $score = false;
                    }
                    $SESSION->gradescorecache[$gradeitemid]=$score;
                }
            }
            return $SESSION->gradescorecache[$gradeitemid];
        } else {
            // Not the current user, so request the score individually
            $record = $DB->get_record('grade_grades', array(
                'userid'=>$userid, 'itemid'=>$gradeitemid));
            if ($record && !is_null($record->finalgrade)) {
                $score = (($record->finalgrade - $record->rawgrademin) * 100) /
                    ($record->rawgrademax - $record->rawgrademin);
            } else {
                // Treat the case where row exists but is null, same as
                // case where row doesn't exist
                $score = false;
            }
            return $score;
        }
    }

    /**
     * For testing only. Wipes information cached in user session.
     *
     * @global stdClass $SESSION
     */
    static function wipe_session_cache() {
        global $SESSION;
        unset($SESSION->gradescorecache);
        unset($SESSION->gradescorecacheuserid);
    }

    /**
     * Utility function called by modedit.php; updates the
     * course_modules_availability table based on the module form data.
     *
     * @param object $cm Course-module with as much data as necessary, min id
     * @param object $fromform
     * @param bool $wipefirst Defaults to true
     */
    public static function update_cm_from_form($cm, $fromform, $wipefirst=true) {
        $ci=new condition_info($cm, CONDITION_MISSING_EVERYTHING, false);
        if ($wipefirst) {
            $ci->wipe_conditions();
        }
        foreach ($fromform->conditiongradegroup as $record) {
            if($record['conditiongradeitemid']) {
                $ci->add_grade_condition($record['conditiongradeitemid'],
                    $record['conditiongrademin'],$record['conditiongrademax']);
            }
        }
        if(isset ($fromform->conditioncompletiongroup)) {
            foreach($fromform->conditioncompletiongroup as $record) {
                if($record['conditionsourcecmid']) {
                    $ci->add_completion_condition($record['conditionsourcecmid'],
                        $record['conditionrequiredcompletion']);
                }
            }
        }
    }

    /**
     * Used in course/lib.php because we need to disable the completion JS if
     * a completion value affects a conditional activity.
     *
     * @global stdClass $CONDITIONLIB_PRIVATE
     * @param object $course Moodle course object
     * @param object $cm Moodle course-module
     * @return bool True if this is used in a condition, false otherwise
     */
    public static function completion_value_used_as_condition($course, $cm) {
        // Have we already worked out a list of required completion values
        // for this course? If so just use that
        global $CONDITIONLIB_PRIVATE;
        if (!array_key_exists($course->id, $CONDITIONLIB_PRIVATE->usedincondition)) {
            // We don't have data for this course, build it
            $modinfo = get_fast_modinfo($course);
            $CONDITIONLIB_PRIVATE->usedincondition[$course->id] = array();
            foreach ($modinfo->cms as $othercm) {
                foreach ($othercm->conditionscompletion as $cmid=>$expectedcompletion) {
                    $CONDITIONLIB_PRIVATE->usedincondition[$course->id][$cmid] = true;
                }
            }
        }
        return array_key_exists($cm->id, $CONDITIONLIB_PRIVATE->usedincondition[$course->id]);
    }
}
