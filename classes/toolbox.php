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
 * Grid Format.
 *
 * @package   format_grid
 * @version   See the value of '$plugin->version' in version.php.
 * @copyright &copy; 2021-onwards G J Barnard based upon work done by Marina Glancy.
 * @author    G J Barnard - {@link http://about.me/gjbarnard} and
 *                          {@link http://moodle.org/user/profile.php?id=442195}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_grid;

/**
 * The format's toolbox.
 *
 * @copyright  &copy; 2021-onwards G J Barnard.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later.
 */
class toolbox {
    /**
     * @var toolbox Singleton instance of us.
     */
    protected static $instance = null;

    // Width constants - 128, 192, 210, 256, 320, 384, 448, 512, 576, 640, 704 and 768:...
    private static $imagecontainerwidths = array(128 => '128', 192 => '192', 210 => '210', 256 => '256', 320 => '320',
        384 => '384', 448 => '448', 512 => '512', 576 => '576', 640 => '640', 704 => '704', 768 => '768');
    // Ratio constants - 3-2, 3-1, 3-3, 2-3, 1-3, 4-3 and 3-4:...
    private static $imagecontainerratios = array(
        1 => '3-2', 2 => '3-1', 3 => '3-3', 4 => '2-3', 5 => '1-3', 6 => '4-3', 7 => '3-4');

    /**
     * This is a lonely object.
     */
    private function __construct() {
    }

    /**
     * Gets the toolbox singleton.
     *
     * @return toolbox The toolbox instance.
     */
    public static function get_instance() {
        if (!is_object(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Prevents ability to change a static variable outside of the class.
     * @return array Array of imagecontainer widths.
     */
    public static function get_image_container_widths() {
        return self::$imagecontainerwidths;
    }

    /**
     * Gets the default image container width.
     * @return int Default image container width.
     */
    public static function get_default_image_container_width() {
        return 210;
    }

    /**
     * Prevents ability to change a static variable outside of the class.
     * @return array Array of image container ratios.
     */
    public static function get_image_container_ratios() {
        return self::$imagecontainerratios;
    }

    /**
     * Gets the default image container ratio.
     * @return int Default image container ratio.
     */
    public static function get_default_image_container_ratio() {
        return 1; // Ratio of '3-2'.
    }

    /**
     * Set up the displayed image.
     * @param array $sectionimage Section information from its row in the 'format_grid_image' table.
     * @param stored_file $sectionfile Section file.
     * @param int $courseid The course id to which the image relates.
     * @param int $sectionid The section id to which the image relates.
     * @param stdClass $format The course format image that the image belongs to.
     * @return array The updated $sectionimage data.
     */
    public function setup_displayed_image($sectionimage, $sectionfile, $courseid, $sectionid, $format) {
        global $CFG, $DB;
        // require_once($CFG->dirroot . '/repository/lib.php');
        // require_once($CFG->libdir . '/gdlib.php');

        // Get the settings!
        $settings = $format->get_settings();
        /*static $settings = array(
            'imagecontainerwidth' => 210,
            'imagecontainerratio' => 1,
            'imageresizemethod' => 2
        );*/

        if (!empty($sectionfile)) {
            $fs = get_file_storage();
            $mime = $sectionfile->get_mimetype();

            $displayedimageinfo = $this->get_displayed_image_container_properties($settings);
            $tmproot = make_temp_directory('gridformatdisplayedimagecontainer');
            $tmpfilepath = $tmproot . '/' . $sectionfile->get_contenthash();
            $sectionfile->copy_content_to($tmpfilepath);

            // $crop = ($settings['imageresizemethod'] == 1) ? false : true;
            $crop = (get_config('format_grid', 'defaultimageresizemethod') == 1) ? false : true;
            $iswebp = (get_config('format_grid', 'defaultdisplayedimagefiletype') == 2);
            if ($iswebp) { // WebP.
                $newmime = 'image/webp';
            } else {
                $newmime = $mime;
            }

            $filename = $sectionfile->get_filename();
            $debugdata = array(
                'itemid' => $sectionfile->get_itemid(),
                'filename' => $filename,
                'sectionid' => $sectionid
            );
            $data = self::generate_image($tmpfilepath, $displayedimageinfo['width'], $displayedimageinfo['height'], $crop, $newmime, $debugdata);
            if (!empty($data)) {
                // Updated image.
                $coursecontext = \context_course::instance($courseid);

                // Remove existing displayed image.
                $existingfiles = $fs->get_area_files($coursecontext->id, 'format_grid', 'displayedsectionimage', $sectionid);
                foreach ($existingfiles as $existingfile) {
                    if (!$existingfile->is_directory()) {
                        $existingfile->delete();
                    }
                }

                $created = time();
                $displayedimagefilerecord = array(
                    'contextid' => $coursecontext->id,
                    'component' => 'format_grid',
                    'filearea' => 'displayedsectionimage',
                    'itemid' => $sectionid,
                    'filepath' => '/',
                    'filename' => $filename,
                    'userid' => $sectionfile->get_userid(),
                    'author' => $sectionfile->get_author(),
                    'license' => $sectionfile->get_license(),
                    'timecreated' => $created,
                    'timemodified' => $created,
                    'mimetype' => $mime);

                if ($iswebp) { // WebP.
                    // Displayed image is a webp image from the original, so change a few things.
                    $displayedimagefilerecord['filename'] = $displayedimagefilerecord['filename'].'.webp';
                    $displayedimagefilerecord['mimetype'] = $newmime;
                }
                $fs->create_file_from_string($displayedimagefilerecord, $data);
                $sectionimage->displayedimagestate++; // Generated.
            } else {
                $sectionimage->displayedimagestate = -1; // Cannot generate.
            }
            unlink($tmpfilepath);

            $DB->set_field('format_grid_image', 'displayedimagestate', $sectionimage->displayedimagestate,
                array('sectionid' => $sectionid));
            if ($sectionimage->displayedimagestate == -1) {
                print_error('cannotconvertuploadedimagetodisplayedimage', 'format_grid',
                    $CFG->wwwroot."/course/view.php?id=".$courseid,
                    'SI: '.var_export($displayedimagefilerecord, true).', DII: '.var_export($displayedimageinfo, true));
            }
        }

        return $sectionimage;
    }

    /**
     * Gets the image container properties given the settings.
     * @param array $settings Must have integer keys for 'imagecontainerwidth' and 'imagecontainerratio'.
     * @return array with the key => value of 'height' and 'width' for the container.
     */
    public function get_displayed_image_container_properties($settings) {
        return array('height' => self::calculate_height($settings['imagecontainerwidth'], $settings['imagecontainerratio']),
            'width' => $settings['imagecontainerwidth']);
    }

    /**
     * Calculates height given the width and ratio.
     * @param int $width The width.
     * @param int $ratio The ratio.
     * @return int Height.
     */
    private static function calculate_height($width, $ratio) {
        $basewidth = $width;

        switch ($ratio) {
            // Ratios 1 => '3-2', 2 => '3-1', 3 => '3-3', 4 => '2-3', 5 => '1-3', 6 => '4-3', 7 => '3-4'.
            case 1: // 3-2.
            case 2: // 3-1.
            case 3: // 3-3.
            case 7: // 3-4.
                $basewidth = $width / 3;
                break;
            case 4: // 2-3.
                $basewidth = $width / 2;
                break;
            case 5: // 1-3.
                $basewidth = $width;
                break;
            case 6: // 4-3.
                $basewidth = $width / 4;
                break;
        }

        $height = $basewidth;
        switch ($ratio) {
            // Ratios 1 => '3-2', 2 => '3-1', 3 => '3-3', 4 => '2-3', 5 => '1-3', 6 => '4-3', 7 => '3-4'.
            case 2: // 3-1.
                $height = $basewidth;
                break;
            case 1: // 3-2.
                $height = $basewidth * 2;
                break;
            case 3: // 3-3.
            case 4: // 2-3.
            case 5: // 1-3.
            case 6: // 4-3.
                $height = $basewidth * 3;
                break;
            case 7: // 3-4.
                $height = $basewidth * 4;
                break;
        }

        return round($height);
    }

    /**
     * Generates a thumbnail for the given image
     *
     * If the GD library has at least version 2 and PNG support is available, the returned data
     * is the content of a transparent PNG file containing the thumbnail. Otherwise, the function
     * returns contents of a JPEG file with black background containing the thumbnail.
     *
     * @param string $filepath the full path to the original image file
     * @param int $requestedwidth the width of the requested image.
     * @param int $requestedheight the height of the requested image.
     * @param bool $crop false = scale, true = crop.
     * @param string $mime The mime type.
     * @param array $debugdata Debug data if the image generation fails.
     *
     * @return string|bool false if a problem occurs or the image data.
     */
    private static function generate_image($filepath, $requestedwidth, $requestedheight, $crop, $mime, $debugdata) {
        if (empty($filepath) or empty($requestedwidth) or empty($requestedheight)) {
            return false;
        }

        global $CFG;
        // require_once($CFG->dirroot . '/repository/lib.php');
        require_once($CFG->libdir . '/gdlib.php');

        $imageinfo = getimagesize($filepath);

        if (empty($imageinfo)) {
            unlink($filepath);
            print_error('noimageinformation', 'format_grid', '', self::debugdata_decode($debugdata), 'generate_image');
            return false;
        }

        $originalwidth = $imageinfo[0];
        $originalheight = $imageinfo[1];

        if (empty($originalheight)) {
            unlink($filepath);
            print_error('originalheightempty', 'format_grid', '', self::debugdata_decode($debugdata), 'generate_image');
            return false;
        }
        if (empty($originalwidth)) {
            unlink($filepath);
            print_error('originalwidthempty', 'format_grid', '', self::debugdata_decode($debugdata), 'generate_image');
            return false;
        }

        $original = imagecreatefromstring(file_get_contents($filepath)); // Need to alter / check for webp support.

        switch ($mime) {
            case 'image/png':
                if (function_exists('imagepng')) {
                    $imagefnc = 'imagepng';
                    $filters = PNG_NO_FILTER;
                    $quality = 1;
                } else {
                    unlink($filepath);
                    print_error('formatnotsupported', 'format_grid', '', 'PNG, '.self::debugdata_decode($debugdata), 'generate_image');
                    return false;
                }
                break;
            case 'image/jpeg':
                if (function_exists('imagejpeg')) {
                    $imagefnc = 'imagejpeg';
                    $filters = null;
                    $quality = 90;
                } else {
                    unlink($filepath);
                    print_error('formatnotsupported', 'format_grid', '', 'JPG, '.self::debugdata_decode($debugdata), 'generate_image');
                    return false;
                }
                break;
            /* Moodle does not yet natively support webp as a mime type, but have here for us on the displayed image and
               not yet as a source image. */
            case 'image/webp':
                if (function_exists('imagewebp')) {
                    $imagefnc = 'imagewebp';
                    $filters = null;
                    $quality = 90;
                } else {
                    unlink($filepath);
                    print_error('formatnotsupported', 'format_grid', '', 'WEBP, '.self::debugdata_decode($debugdata), 'generate_image');
                    return false;
                }
                break;
            case 'image/gif':
                if (function_exists('imagegif')) {
                    $imagefnc = 'imagegif';
                    $filters = null;
                    $quality = null;
                } else {
                    unlink($filepath);
                    print_error('formatnotsupported', 'format_grid', '', 'GIF, '.self::debugdata_decode($debugdata), 'generate_image');
                    return false;
                }
                break;
            default:
                unlink($filepath);
                print_error('mimetypenotsupported', 'format_grid', '', $mime.', '.self::debugdata_decode($debugdata), 'generate_image');
                return false;
        }

        if ($crop) {
            $ratio = $requestedwidth / $requestedheight;
            $originalratio = $originalwidth / $originalheight;
            if ($originalratio < $ratio) {
                // Change the supplied height - 'resizeToWidth'.
                $ratio = $requestedwidth / $originalwidth;
                $width = $requestedwidth;
                $height = $originalheight * $ratio;
                $cropheight = true;
            } else {
                // Change the supplied width - 'resizeToHeight'.
                $ratio = $requestedheight / $originalheight;
                $width = $originalwidth * $ratio;
                $height = $requestedheight;
                $cropheight = false;
            }

            if (function_exists('imagecreatetruecolor')) {
                $tempimage = imagecreatetruecolor($width, $height);
                if ($imagefnc === 'imagepng') {
                    imagealphablending($tempimage, false);
                    imagesavealpha($tempimage, true);
                }
            } else {
                $tempimage = imagecreate($width, $height);
            }

            // First step, resize.
            imagecopybicubic($tempimage, $original, 0, 0, 0, 0, $width, $height, $originalwidth, $originalheight);

            // Second step, crop.
            if ($cropheight) {
                // This is 'cropCenterHeight'.
                $srcoffset = ($height / 2) - ($requestedheight / 2);
                $height = $requestedheight;
            } else {
                // This is 'cropCenterWidth'.
                $srcoffset = ($width / 2) - ($requestedwidth / 2);
                $width = $requestedwidth;
            }

            if (function_exists('imagecreatetruecolor')) {
                $finalimage = imagecreatetruecolor($width, $height);
                if ($imagefnc === 'imagepng') {
                    imagealphablending($finalimage, false);
                    imagesavealpha($finalimage, true);
                }
            } else {
                $finalimage = imagecreate($width, $height);
            }

            if ($cropheight) {
                // This is 'cropCenterHeight'.
                imagecopybicubic($finalimage, $tempimage, 0, 0, 0, $srcoffset, $width, $height, $width, $height);
            } else {
                // This is 'cropCenterWidth'.
                imagecopybicubic($finalimage, $tempimage, 0, 0, $srcoffset, 0, $width, $height, $width, $height);
            }
            imagedestroy($tempimage);
        } else { // Scale.
            $ratio = min($requestedwidth / $originalwidth, $requestedheight / $originalheight);

            if ($ratio < 1) {
                $targetwidth = floor($originalwidth * $ratio);
                $targetheight = floor($originalheight * $ratio);
            } else {
                // Do not enlarge the original file if it is smaller than the requested thumbnail size.
                $targetwidth = $originalwidth;
                $targetheight = $originalheight;
            }

            if (function_exists('imagecreatetruecolor')) {
                $finalimage = imagecreatetruecolor($targetwidth, $targetheight);
                if ($imagefnc === 'imagepng') {
                    imagealphablending($finalimage, false);
                    imagesavealpha($finalimage, true);
                }
            } else {
                $finalimage = imagecreate($targetwidth, $targetheight);
            }

            imagecopybicubic($finalimage, $original, 0, 0, 0, 0, $targetwidth, $targetheight, $originalwidth, $originalheight);
        }

        ob_start();
        if (!$imagefnc($finalimage, null, $quality, $filters)) {
            ob_end_clean();
            unlink($filepath);
            print_error('functionfailed', 'format_grid', '', $imagefnc.', '.self::debugdata_decode($debugdata), 'generate_image');
            return false;
        }
        $data = ob_get_clean();

        imagedestroy($original);
        imagedestroy($finalimage);

        return $data;
    }

    private static function debugdata_decode($debugdata) {
        $o = 'itemid > '.$debugdata['itemid'];
        $o .= ', filename > '.$debugdata['filename'];
        $o .= ' and sectionid > '.$debugdata['sectionid'].'.  ';
        $o .= get_string('reporterror', 'format_grid');

        return $o;
    }

    /**
     * Update images callback.
     */
    public static function update_displayed_images_callback() {
        self::update_the_displayed_images();
    }

    /**
     * Update images.
     *
     * @param int $courseid The course id.
     *
     */
    public static function update_displayed_images($courseid) {
        self::update_the_displayed_images($courseid);
    }

    /**
     * Update images.
     *
     * @param int $courseid The course id.
     *
     */
    private static function update_the_displayed_images($courseid = null) {
        global $DB;

        if (!empty($courseid)) {
            $coursesectionimages = $DB->get_records('format_grid_image', array('courseid' => $courseid));
        } else {
            $coursesectionimages = $DB->get_records('format_grid_image');
        }
        // error_log('update_displayed_images_callback - '.print_r($coursesectionimages, true));
        if (!empty($coursesectionimages)) {
            $fs = get_file_storage();
            $lockfactory = \core\lock\lock_config::get_lock_factory('format_grid');
            $toolbox = self::get_instance();
            $courseid = -1;
            foreach ($coursesectionimages as $coursesectionimage) {
                if ($courseid != $coursesectionimage->courseid) {
                    $courseid = $coursesectionimage->courseid;                
                    $format = course_get_format($courseid);
                }
                $coursecontext = \context_course::instance($courseid);
                if ($lock = $lockfactory->get_lock('sectionid'.$coursesectionimage->sectionid, 5)) {
                    $files = $fs->get_area_files($coursecontext->id, 'format_grid', 'sectionimage', $coursesectionimage->sectionid);
                    foreach ($files as $file) {
                        if (!$file->is_directory()) {
                            // error_log('f '.$coursesectionimage->sectionid.' - '.print_r($file->get_filename(), true));
                            try {
                                $coursesectionimage = $toolbox->setup_displayed_image($coursesectionimage, $file, $courseid, $coursesectionimage->sectionid, $format);
                            } catch (\Exception $e) {
                                $lock->release();
                                throw $e;
                            }
                        }
                    }
                    $lock->release();
                } else {
                    throw new \moodle_exception('cannotgetimagelock', 'format_grid', '',
                        get_string('cannotgetmanagesectionimagelock', 'format_grid'));
                }
            }
        }
    }

    public static function delete_images($courseid) {
        global $DB;

        $fs = get_file_storage();
        $coursecontext = \context_course::instance($courseid);
        // Images.
        $images = $fs->get_area_files($coursecontext->id, 'format_grid', 'sectionimage');
        foreach ($images as $image) {
            if (!$image->is_directory()) {
                $image->delete();
            }
        }

        // Displayed images.
        $displayedimages = $fs->get_area_files($coursecontext->id, 'format_grid', 'sectionimage');
        foreach ($displayedimages as $displayedimage) {
            if (!$displayedimage->is_directory()) {
                $displayedimage->delete();
            }
        }

        $DB->delete_records("format_grid_image", array('courseid' => $courseid));
    }
}
