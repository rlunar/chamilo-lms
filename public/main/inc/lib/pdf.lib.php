<?php

/* See license terms in /license.txt */

use Chamilo\CoreBundle\Framework\Container;
use Masterminds\HTML5;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use Mpdf\Utils\UtfString;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class PDF.
 */
class PDF
{
    /** @var Mpdf */
    public $pdf;
    public $custom_header = [];
    public $custom_footer = [];
    public $params = [];
    public $template;

    /**
     * Creates the mPDF object.
     *
     * @param string   $pageFormat  format A4 A4-L see
     *                              http://mpdf1.com/manual/index.php?tid=184&searchstring=format
     * @param string   $orientation orientation "P" = Portrait "L" = Landscape
     * @param array    $params
     * @param Template $template
     *
     * @throws MpdfException
     */
    public function __construct(
        $pageFormat = 'A4',
        $orientation = 'P',
        $params = [],
        $template = null
    ) {
        $this->template = $template;
        /* More info @ http://mpdf1.com/manual/index.php?tid=184&searchstring=Mpdf */
        if (!in_array($orientation, ['P', 'L'])) {
            $orientation = 'P';
        }
        //left, right, top, bottom, margin_header, margin footer

        $params['left'] = $params['left'] ?? 15;
        $params['right'] = $params['right'] ?? 15;
        $params['top'] = $params['top'] ?? 30;
        $params['bottom'] = $params['bottom'] ?? 30;
        $params['margin_footer'] = $params['margin_footer'] ?? 8;

        $this->params['filename'] = $params['filename'] ?? api_get_local_time();
        $this->params['pdf_title'] = $params['pdf_title'] ?? '';
        $this->params['course_info'] = $params['course_info'] ?? api_get_course_info();
        $this->params['session_info'] = $params['session_info'] ?? api_get_session_info(api_get_session_id());
        $this->params['course_code'] = $params['course_code'] ?? api_get_course_id();
        $this->params['add_signatures'] = $params['add_signatures'] ?? [];
        $this->params['show_real_course_teachers'] = $params['show_real_course_teachers'] ?? false;
        $this->params['student_info'] = $params['student_info'] ?? false;
        $this->params['show_grade_generated_date'] = $params['show_grade_generated_date'] ?? false;
        $this->params['show_teacher_as_myself'] = $params['show_teacher_as_myself'] ?? true;
        $localTime = api_get_local_time();
        $this->params['pdf_date'] = $params['pdf_date'] ?? api_format_date($localTime, DATE_TIME_FORMAT_LONG);
        $this->params['pdf_date_only'] = $params['pdf_date'] ?? api_format_date($localTime, DATE_FORMAT_LONG);

        $params = [
            'tempDir' => Container::getParameter('kernel.cache_dir').'/mpdf',
            'mode' => 'utf-8',
            'format' => $pageFormat,
            'orientation' => $orientation,
            'margin_left' => $params['left'],
            'margin_right' => $params['right'],
            'margin_top' => $params['top'],
            'margin_bottom' => $params['bottom'],
            'margin_header' => 8,
            'margin_footer' => 8,
        ];

        // Default value is 96 set in the mpdf library file config.php
        $value = (int) api_get_setting('platform.pdf_img_dpi');
        if ($value) {
            $params['img_dpi'] = $value;
        }
        $this->pdf = new Mpdf($params);
    }

    /**
     * Export the given HTML to PDF, using a global template.
     *
     * @uses \export/table_pdf.tpl
     *
     * @param string     $content
     * @param bool|false $saveToFile
     * @param bool|false $returnHtml
     * @param bool       $addDefaultCss (bootstrap/default/base.css)
     * @param array
     *
     * @return string
     */
    public function html_to_pdf_with_template(
        $content,
        $saveToFile = false,
        $returnHtml = false,
        $addDefaultCss = false,
        $extraRows = [],
        $outputMode = 'D',
        $fileToSave = null
    ) {
        if (empty($this->template)) {
            $tpl = new Template('', false, false, false, false, true, false);
        } else {
            $tpl = $this->template;
        }

        // Assignments
        $tpl->assign('pdf_content', $content);

        // Showing only the current teacher/admin instead the all teacher list name see BT#4080
        $teacher_list = null;
        if (isset($this->params['show_real_course_teachers']) &&
            $this->params['show_real_course_teachers']
        ) {
            if (isset($this->params['session_info']) &&
                !empty($this->params['session_info'])
            ) {
                $teacher_list = SessionManager::getCoachesByCourseSessionToString(
                    $this->params['session_info']['id'],
                    $this->params['course_info']['real_id']
                );
            } else {
                $teacher_list = CourseManager::getTeacherListFromCourseCodeToString(
                    $this->params['course_code']
                );
            }
        } else {
            $user_info = api_get_user_info();
            if ($this->params['show_teacher_as_myself']) {
                $teacher_list = $user_info['complete_name'];
            }
        }

        if (!empty($this->params['session_info']['title'])) {
            $this->params['session_info']['title'] = preg_replace('/[\x{2600}-\x{26FF}]/u', '', $this->params['session_info']['title']);
        }

        $tpl->assign('pdf_course', $this->params['course_code']);
        $tpl->assign('pdf_course_info', $this->params['course_info']);
        $tpl->assign('pdf_session_info', $this->params['session_info']);
        $tpl->assign('pdf_date', $this->params['pdf_date']);
        $tpl->assign('pdf_date_only', $this->params['pdf_date_only']);
        $tpl->assign('pdf_teachers', $teacher_list);
        $tpl->assign('pdf_title', $this->params['pdf_title']);
        $tpl->assign('pdf_student_info', $this->params['student_info']);
        $tpl->assign('show_grade_generated_date', $this->params['show_grade_generated_date']);
        $tpl->assign('add_signatures', $this->params['add_signatures']);
        $tpl->assign('extra_rows', $extraRows);

        // Getting template
        $tableTemplate = $tpl->get_template('export/table_pdf.tpl');
        $html = $tpl->fetch($tableTemplate);
        $html = api_utf8_encode($html);
        $html = $this->replaceIconsWithImages($html);

        if ($returnHtml) {
            return $html;
        }

        $css = Container::getThemeHelper()->getAssetContents('print.css');

        self::content_to_pdf(
            $html,
            $css,
            $this->params['filename'],
            $this->params['course_code'],
            $outputMode,
            $saveToFile,
            $fileToSave,
            $returnHtml,
            $addDefaultCss
        );
    }

    /**
     * Converts HTML files to PDF.
     *
     * @param mixed  $htmlFileArray  could be an html file path or an array
     *                               with paths example:
     *                               /var/www/myfile.html or array('/myfile.html','myotherfile.html') or
     *                               even an indexed array with both 'title' and 'path' indexes
     *                               for each element like
     *                               array(
     *                               0 => array('title'=>'Hello','path'=>'file.html'),
     *                               1 => array('title'=>'Bye','path'=>'file2.html')
     *                               );
     * @param string $pdfName        pdf name
     * @param string $courseCode     (if you are using html that are located
     *                               in the document tool you must provide this)
     * @param bool   $complete_style show header and footer if true
     * @param bool   $addStyle
     * @param string $mainTitle
     *
     * @return false|null
     */
    public function html_to_pdf(
        $htmlFileArray,
        $pdfName = '',
        $courseCode = null,
        $printTitle = false,
        $complete_style = true,
        $addStyle = true,
        $mainTitle = ''
    ) {
        if (empty($htmlFileArray)) {
            return false;
        }

        if (!is_array($htmlFileArray)) {
            if (!file_exists($htmlFileArray)) {
                return false;
            }
            // Converting the string into an array
            $htmlFileArray = [$htmlFileArray];
        }

        $courseInfo = api_get_course_info();
        if (!empty($courseCode)) {
            $courseInfo = api_get_course_info($courseCode);
        }

        // Clean styles and javascript document
        $clean_search = [
            '@<script[^>]*?>.*?</script>@si',
            '@<style[^>]*?>.*?</style>@si',
        ];

        // Formatting the pdf
        self::format_pdf($courseInfo, $complete_style);

        $counter = 1;
        foreach ($htmlFileArray as $file) {
            $pageBreak = ($counter === count($htmlFileArray)) ? '' : '<pagebreak>';
            $htmlTitle = '';
            $filePath = null;
            $content = null;

            if (is_array($file)) {
                $htmlTitle = $file['title'] ?? '';
                $content = $file['content'] ?? null;
                $filePath = $file['path'] ?? null;
            } else {
                $filePath = $file;
                $htmlTitle = basename($file);
            }

            if ($counter === 1 && !empty($mainTitle)) {
                $this->pdf->WriteHTML('<html><body><h2 style="text-align: center">'.$mainTitle.'</h2></body></html>');
            }

            // New support for direct HTML content
            if (!empty($content)) {
                if ($printTitle && !empty($htmlTitle)) {
                    $this->pdf->WriteHTML('<html><body><h3>'.$htmlTitle.'</h3></body></html>', 2);
                }
                $this->pdf->WriteHTML($content.$pageBreak, 2);
                $counter++;
                continue;
            }

            // Original logic for physical files
            if (empty($filePath)) {
                if ($printTitle && !empty($htmlTitle)) {
                    $this->pdf->WriteHTML('<html><body><h3>'.$htmlTitle.'</h3></body></html>'.$pageBreak);
                }
                $counter++;
                continue;
            }

            if (!file_exists($filePath)) {
                $counter++;
                continue;
            }

            if ($addStyle) {
                $css_file = api_get_path(SYS_CSS_PATH).'/print.css';
                $css = file_exists($css_file) ? @file_get_contents($css_file) : '';
                $this->pdf->WriteHTML($css, 1);
            }

            if ($printTitle && !empty($htmlTitle)) {
                $this->pdf->WriteHTML('<html><body><h3>'.$htmlTitle.'</h3></body></html>', 2);
            }

            $file_info = pathinfo($filePath);
            $extension = $file_info['extension'];

            if (in_array($extension, ['html', 'htm'])) {
                $dirName = $file_info['dirname'];
                $filename = str_replace('_', ' ', $file_info['basename']);
                $filename = basename($filename, '.'.$extension);

                $webPath = api_get_path(WEB_PATH);

                $documentHtml = @file_get_contents($filePath);
                $documentHtml = preg_replace($clean_search, '', $documentHtml);

                $crawler = new Crawler($documentHtml);
                $crawler
                    ->filter('link[rel="stylesheet"]')
                    ->each(function (Crawler $node) use ($webPath) {
                        $linkUrl = $node->link()->getUri();
                        if (!str_starts_with($linkUrl, $webPath)) {
                            $node->getNode(0)->parentNode->removeChild($node->getNode(0));
                        }
                    })
                ;
                $documentHtml = $crawler->outerHtml();

                //absolute path for frames.css //TODO: necessary?
                $absolute_css_path = api_get_path(WEB_CODE_PATH).'css/'.api_get_setting('stylesheets.stylesheets').'/frames.css';
                $documentHtml = str_replace('href="./css/frames.css"', $absolute_css_path, $documentHtml);
                if (!empty($courseInfo['path'])) {
                    $documentHtml = str_replace('../', '', $documentHtml);

                    // Fix app/upload links convert web to system paths
                    $documentHtml = str_replace(
                        api_get_path(WEB_UPLOAD_PATH),
                        api_get_path(SYS_UPLOAD_PATH),
                        $documentHtml
                    );
                }

                api_set_encoding_html($documentHtml, 'UTF-8');

                if (!empty($documentHtml)) {
                    $this->pdf->WriteHTML($documentHtml.$pageBreak, 2);
                }
            } elseif (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                $image = Display::img($filePath);
                $this->pdf->WriteHTML('<html><body>'.$image.'</body></html>'.$pageBreak, 2);
            }

            $counter++;
        }

        $outputFile = 'pdf_'.api_get_local_time().'.pdf';
        if (!empty($pdfName)) {
            $outputFile = $pdfName.'.pdf';
        }

        $outputFile = api_replace_dangerous_char($outputFile);

        // F to save the pdf in a file
        $this->pdf->Output($outputFile, Destination::DOWNLOAD);
        exit;
    }

    /**
     * Converts an html string to PDF.
     *
     * @param string $document_html  valid html
     * @param string $css            CSS content of a CSS file
     * @param string $pdf_name       pdf name
     * @param string $courseCode     course code
     *                               (if you are using html that are located in the document tool you must provide this)
     * @param string $outputMode     the MPDF output mode can be:
     * @param bool   $saveInFile
     * @param string $fileToSave
     * @param bool   $returnHtml
     * @param bool   $addDefaultCss
     * @param bool   $completeHeader
     *
     * 'I' (print on standard output),
     * 'D' (download file) (this is the default value),
     * 'F' (save to local file) or
     * 'S' (return as a string)
     *
     * @return string Web path
     */
    public function content_to_pdf(
        $document_html,
        ?string $css = null,
        $pdf_name = '',
        $courseCode = null,
        $outputMode = 'D',
        $saveInFile = false,
        $fileToSave = null,
        $returnHtml = false,
        $addDefaultCss = false,
        $completeHeader = true,
        $disableFooter = false,
        $disablePagination = false
    ) {
        $urlAppend = '';

        if (empty($document_html)) {
            return false;
        }

        // clean styles and javascript document
        $clean_search = [
            '@<script[^>]*?>.*?</script>@si',
            '@<style[^>]*?>.*?</style>@siU',
        ];

        // Formatting the pdf
        $courseInfo = api_get_course_info($courseCode);
        $this->format_pdf($courseInfo, $completeHeader, $disablePagination);
        $document_html = preg_replace($clean_search, '', $document_html);

        $document_html = str_replace('../../', '', $document_html);
        $document_html = str_replace('../', '', $document_html);
        $document_html = str_replace(
            (empty($urlAppend) ? '' : $urlAppend.'/').'courses/'.$courseCode.'/document/',
            '',
            $document_html
        );

        $basicStyles = [];

        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $document_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $linksToRemove = [];

        foreach ($doc->getElementsByTagName('link') as $link) {
            if ($link->getAttribute('href') === './css/frames.css') {
                $linksToRemove[] = $link;
            }
        }

        foreach ($linksToRemove as $link) {
            $link->parentNode->removeChild($link);
        }

        $basicStyles[] = Container::getThemeHelper()->getAssetContents('frames.css');

        $document_html = $doc->saveHTML();

        if (!empty($courseInfo['path'])) {
            //Fixing only images @todo do the same thing with other elements
            $elements = $doc->getElementsByTagName('img');
            $protocol = api_get_protocol();
            $replaced = [];
            if (!empty($elements)) {
                foreach ($elements as $item) {
                    $old_src = $item->getAttribute('src');

                    if (in_array($old_src, $replaced)) {
                        continue;
                    }
                }
            }
        }

        // Use sys path to correct export images
        $document_html = str_replace(
            api_get_path(WEB_CODE_PATH).'img/',
            api_get_path(SYS_CODE_PATH).'img/',
            $document_html
        );
        $document_html = str_replace(api_get_path(WEB_ARCHIVE_PATH), api_get_path(SYS_ARCHIVE_PATH), $document_html);
        $document_html = str_replace('<?xml encoding="UTF-8">', '', $document_html);
        // The library mPDF expects UTF-8 encoded input data.
        api_set_encoding_html($document_html, 'UTF-8');
        // At the moment the title is retrieved from the html document itself.
        if ($returnHtml) {
            return "<style>$css</style>".$document_html;
        }

        $css .= "
            table {
                width: 100%;
                border-collapse: collapse;
            }
            th, td {
                font-size: 12px;
                text-align: left;
                padding: 2px;
                border: 1px solid #ccc;
            }
        ";

        if (!empty($css)) {
            $this->pdf->WriteHTML($css, HTMLParserMode::HEADER_CSS);
        }

        if ($addDefaultCss) {
            $basicStyles[] = Container::getThemeHelper()->getAssetContents('default.css');
        }

        foreach ($basicStyles as $cssContent) {
            if ($cssContent) {
                @$this->pdf->WriteHTML($cssContent, HTMLParserMode::HEADER_CSS);
            }
        }

        @$this->pdf->WriteHTML($document_html);
        if ($disableFooter) {
            $this->pdf->SetHTMLFooter('');
        }

        if (empty($pdf_name)) {
            $output_file = 'pdf_'.date('Y-m-d-his').'.pdf';
        } else {
            $pdf_name = api_replace_dangerous_char($pdf_name);
            $output_file = $pdf_name.'.pdf';
        }

        if ('F' === $outputMode) {
            $output_file = api_get_path(SYS_ARCHIVE_PATH).$output_file;
        }

        if ($saveInFile) {
            $fileToSave = !empty($fileToSave) ? $fileToSave : api_get_path(SYS_ARCHIVE_PATH).uniqid();

            $this->pdf->Output(
                $fileToSave,
                $outputMode
            ); // F to save the pdf in a file
        } else {
            $this->pdf->Output(
                $output_file,
                $outputMode
            );
        }

        if ('F' !== $outputMode) {
            exit;
        }

        return $output_file;
    }

    /**
     * Gets the watermark from the platform or a course.
     *
     * @param   string  course code (optional)
     * @param   mixed   web path of the watermark image, false if there is nothing to return
     *
     * @return string
     */
    public static function get_watermark($courseCode = null)
    {
        $web_path = false;
        $urlId = api_get_current_access_url_id();
        if (!empty($courseCode) && 'true' == api_get_setting('document.pdf_export_watermark_by_course')) {
            $course_info = api_get_course_info($courseCode);
            // course path
            $store_path = api_get_path(SYS_COURSE_PATH).$course_info['path'].'/'.$urlId.'_pdf_watermark.png';
            if (file_exists($store_path)) {
                $web_path = api_get_path(WEB_COURSE_PATH).$course_info['path'].'/'.$urlId.'_pdf_watermark.png';
            }
        } else {
            // course path
            $store_path = api_get_path(SYS_CODE_PATH).'default_course_document/images/'.$urlId.'_pdf_watermark.png';
            if (file_exists($store_path)) {
                $web_path = api_get_path(WEB_CODE_PATH).'default_course_document/images/'.$urlId.'_pdf_watermark.png';
            }
        }

        return $web_path;
    }

    /**
     * Deletes the watermark from the Platform or Course.
     *
     * @param string $courseCode course code (optional)
     * @param   mixed   web path of the watermark image, false if there is nothing to return
     *
     * @return bool
     */
    public static function delete_watermark($courseCode = null)
    {
        $urlId = api_get_current_access_url_id();
        if (!empty($courseCode) && 'true' === api_get_setting('document.pdf_export_watermark_by_course')) {
            $course_info = api_get_course_info($courseCode);
            // course path
            $store_path = api_get_path(SYS_COURSE_PATH).$course_info['path'].'/'.$urlId.'_pdf_watermark.png';
        } else {
            // course path
            $store_path = api_get_path(SYS_CODE_PATH).'default_course_document/images/'.$urlId.'_pdf_watermark.png';
        }
        if (file_exists($store_path)) {
            unlink($store_path);

            return true;
        }

        return false;
    }

    /**
     * Uploads the pdf watermark in the main/default_course_document directory or in the course directory.
     *
     * @param string $filename    filename
     * @param string $source_file path of the file
     * @param string $courseCode
     *
     * @return mixed web path of the file if sucess, false otherwise
     */
    public static function upload_watermark($filename, $source_file, $courseCode = null)
    {
        $urlId = api_get_current_access_url_id();
        if (!empty($courseCode) && 'true' === api_get_setting('document.pdf_export_watermark_by_course')) {
            $course_info = api_get_course_info($courseCode);
            $store_path = api_get_path(SYS_COURSE_PATH).$course_info['path']; // course path
            $web_path = api_get_path(WEB_COURSE_PATH).$course_info['path'].'/pdf_watermark.png';
        } else {
            $store_path = api_get_path(SYS_CODE_PATH).'default_course_document/images'; // course path
            $web_path = api_get_path(WEB_CODE_PATH).'default_course_document/images/'.$urlId.'_pdf_watermark.png';
        }
        $course_image = $store_path.'/'.$urlId.'_pdf_watermark.png';

        if (file_exists($course_image)) {
            @unlink($course_image);
        }
        $my_image = new Image($source_file);
        $result = $my_image->send_image($course_image, -1, 'png');
        if ($result) {
            $result = $web_path;
        }

        return $result;
    }

    /**
     * Returns the default header.
     */
    public function get_header($courseCode = null)
    {
        /*$header = api_get_setting('pdf_export_watermark_text');
    	if (!empty($courseCode) && api_get_setting('pdf_export_watermark_by_course') == 'true') {
            $header = api_get_course_setting('pdf_export_watermark_text');
        }
        return $header;*/
    }

    /**
     * Sets the PDF footer.
     */
    public function set_footer()
    {
        $this->pdf->defaultfooterfontsize = 12; // in pts
        $this->pdf->defaultfooterfontstyle = 'B'; // blank, B, I, or BI
        $this->pdf->defaultfooterline = 1; // 1 to include line below header/above footer

        $view = new Template('', false, false, false, true, false, false);
        $template = $view->get_template('export/pdf_footer.tpl');
        $footerHTML = $view->fetch($template);

        $this->pdf->SetHTMLFooter($footerHTML, 'E'); //Even pages
        $this->pdf->SetHTMLFooter($footerHTML, 'O'); //Odd pages
    }

    public function setCertificateFooter()
    {
        $this->pdf->defaultfooterfontsize = 12; // in pts
        $this->pdf->defaultfooterfontstyle = 'B'; // blank, B, I, or BI
        $this->pdf->defaultfooterline = 1; // 1 to include line below header/above footer

        $view = new Template('', false, false, false, true, false, false);
        $template = $view->get_template('export/pdf_certificate_footer.tpl');
        $footerHTML = $view->fetch($template);

        $this->pdf->SetHTMLFooter($footerHTML, 'E'); //Even pages
        $this->pdf->SetHTMLFooter($footerHTML, 'O'); //Odd pages
    }

    /**
     * Sets the PDF header.
     *
     * @param array $courseInfo
     */
    public function set_header($courseInfo)
    {
        $this->pdf->defaultheaderfontsize = 10; // in pts
        $this->pdf->defaultheaderfontstyle = 'BI'; // blank, B, I, or BI
        $this->pdf->defaultheaderline = 1; // 1 to include line below header/above footer

        $userId = api_get_user_id();
        if (!empty($courseInfo['code'])) {
            $teacher_list = CourseManager::get_teacher_list_from_course_code($courseInfo['code']);

            $teachers = '';
            if (!empty($teacher_list)) {
                foreach ($teacher_list as $teacher) {
                    if ($teacher['user_id'] != $userId) {
                        continue;
                    }

                    // Do not show the teacher list see BT#4080 only the current teacher name
                    $teachers = api_get_person_name($teacher['firstname'], $teacher['lastname']);
                }
            }

            $logoSrc = Container::getThemeHelper()->getAssetBase64Encoded('images/header-logo.png');
            // Use custom logo image.
            $pdfLogo = api_get_setting('platform.pdf_logo_header');
            if ('true' === $pdfLogo) {
                $logoSrc = Container::getThemeHelper()->getAssetBase64Encoded('images/pdf_logo_header.png') ?: $logoSrc;
            }

            $organization = "<img src='$logoSrc'>";

            $view = new Template('', false, false, false, true, false, false);
            $view->assign('teacher_name', $teachers);
            $view->assign('organization', $organization);
            $template = $view->get_template('export/pdf_header.tpl');
            $headerHTML = $view->fetch($template);

            $this->pdf->SetHTMLHeader($headerHTML, 'E');
            $this->pdf->SetHTMLHeader($headerHTML, 'O');
        }
    }

    /**
     * @param string $header html content
     */
    public function set_custom_header($header)
    {
        $this->custom_header = $header;
    }

    /**
     * @param array $footer html content
     */
    public function set_custom_footer($footer)
    {
        $this->custom_footer = $footer;
    }

    /**
     * Pre-formats a PDF to the right size and, if not stated otherwise, with
     * header, footer and watermark (if any).
     *
     * @param array $courseInfo General course information (to fill headers)
     * @param bool  $complete   Whether we want headers, footers and watermark or not
     */
    public function format_pdf($courseInfo, $complete = true, $disablePagination = false)
    {
        $courseCode = null;
        if (!empty($courseInfo)) {
            $courseCode = $courseInfo['code'];
        }

        $this->pdf->directionality = api_get_text_direction();
        $this->pdf->onlyCoreFonts = true;
        $this->pdf->mirrorMargins = 1;

        // Add decoration only if not stated otherwise
        if ($complete) {
            // Adding watermark
            if ('true' == api_get_setting('document.pdf_export_watermark_enable')) {
                $watermark_file = self::get_watermark($courseCode);
                if ($watermark_file) {
                    $this->pdf->SetWatermarkImage($watermark_file);
                    $this->pdf->showWatermarkImage = true;
                } else {
                    $watermark_file = self::get_watermark(null);
                    if ($watermark_file) {
                        $this->pdf->SetWatermarkImage($watermark_file);
                        $this->pdf->showWatermarkImage = true;
                    }
                }
                $watermark_text = api_get_setting('document.pdf_export_watermark_text');
                if ($courseCode && 'true' === api_get_setting('document.pdf_export_watermark_by_course')) {
                    $courseWaterMark = api_get_course_setting('document.pdf_export_watermark_text');
                    if (!empty($courseWaterMark) && -1 != $courseWaterMark) {
                        $watermark_text = $courseWaterMark;
                    }
                }
                if (!empty($watermark_text)) {
                    $this->pdf->SetWatermarkText(
                        UtfString::strcode2utf($watermark_text),
                        0.1
                    );
                    $this->pdf->showWatermarkText = true;
                }
            }

            if ($disablePagination) {
                $this->pdf->SetHTMLHeader('');
                $this->pdf->SetHTMLFooter('');
            } else {
                if (empty($this->custom_header)) {
                    $this->set_header($courseInfo);
                } else {
                    $this->pdf->SetHTMLHeader($this->custom_header, 'E');
                    $this->pdf->SetHTMLHeader($this->custom_header, 'O');
                }

                if (empty($this->custom_footer)) {
                    self::set_footer();
                } else {
                    $this->pdf->SetHTMLFooter($this->custom_footer);
                }
            }
        }
    }

    /**
     * Generate a PDF file from $html in SYS_APP_PATH.
     *
     * @param string $html     PDF content
     * @param string $fileName File name
     * @param string $dest     Optional. Directory to move file
     *
     * @return string The PDF path
     */
    public function exportFromHtmlToFile($html, $fileName)
    {
        $this->template = $this->template ?: new Template('', false, false, false, false, false, false);

        $css = Container::getThemeHelper()->getAssetContents('print.css');

        $pdfPath = self::content_to_pdf(
            $html,
            $css,
            $fileName,
            $this->params['course_code'],
            'F'
        );

        return $pdfPath;
    }

    /**
     * Create a PDF and save it into the documents area.
     *
     * @param string $htmlContent HTML Content
     * @param string $fileName    The file name
     * @param int    $courseId    The course ID
     * @param int    $sessionId   Optional. The session ID
     */
    public function exportFromHtmlToDocumentsArea(
        $htmlContent,
        $fileName,
        $courseId,
        $sessionId = 0
    ) {
        $userId = api_get_user_id();
        $courseInfo = api_get_course_info_by_id($courseId);

        $docPath = $this->exportFromHtmlToFile(
            $htmlContent,
            $fileName
        );

        DocumentManager::addDocument(
            $courseInfo,
            '',
            'file',
            filesize($docPath),
            $fileName,
            null,
            false,
            true,
            null,
            $sessionId,
            $userId,
            false,
            '',
            null,
            $docPath
        );

        Display::addFlash(Display::return_message(get_lang('Item added')));
    }

    /**
     * @param string $theme
     *
     * @throws MpdfException
     */
    public function setBackground($theme)
    {
        $themeName = empty($theme) ? api_get_visual_theme() : $theme;
        $themeDir = \Template::getThemeDir($themeName);
        $customLetterhead = $themeDir.'images/letterhead.png';
        $urlPathLetterhead = api_get_path(SYS_CSS_PATH).$customLetterhead;

        $urlWebLetterhead = '#FFFFFF';
        $fullPage = false;
        if (file_exists($urlPathLetterhead)) {
            $fullPage = true;
            $urlWebLetterhead = 'url('.api_get_path(WEB_CSS_PATH).$customLetterhead.')';
        }

        if ($fullPage) {
            $this->pdf->SetDisplayMode('fullpage');
            $this->pdf->SetDefaultBodyCSS('background', $urlWebLetterhead);
            $this->pdf->SetDefaultBodyCSS('background-image-resize', '6');
        }
    }

    /**
     * Fix images source paths to allow export to pdf.
     *
     * @param string $documentHtml
     * @param string $dirName
     *
     * @return string
     */
    private static function fixImagesPaths($documentHtml, array $courseInfo = null, $dirName = '')
    {
        $html = new HTML5();
        $doc = $html->loadHTML($documentHtml);

        $elements = $doc->getElementsByTagName('img');

        if (empty($elements)) {
            return $doc->saveHTML();
        }

        $protocol = api_get_protocol();
        $sysCodePath = api_get_path(SYS_CODE_PATH);
        $sysCoursePath = api_get_path(SYS_PATH).'../app/courses/';
        $sysUploadPath = api_get_path(SYS_PATH).'../app/upload/';

        $documentPath = $courseInfo ? $sysCoursePath.$courseInfo['path'].'/document/' : '';

        $notFoundImagePath = Display::return_icon(
            'closed-circle.png',
            get_lang('FileNotFound'),
            [],
            ICON_SIZE_TINY,
            false,
            true
        );

        /** @var \DOMElement $element */
        foreach ($elements as $element) {
            $src = $element->getAttribute('src');
            $src = trim($src);

            if (api_filename_has_blacklisted_stream_wrapper($src)) {
                $element->setAttribute('src', $notFoundImagePath);
                continue;
            }

            if (false !== strpos($src, $protocol)) {
                continue;
            }

            // It's a reference to a file in the system, do not change it
            if (file_exists($src)) {
                continue;
            }

            if (0 === strpos($src, '/main/default_course_document')) {
                $element->setAttribute(
                    'src',
                    str_replace('/main/default_course_document', $sysCodePath.'default_course_document', $src)
                );
                continue;
            }

            if (0 === strpos($src, '/main/img')) {
                $element->setAttribute(
                    'src',
                    str_replace('/main/img/', $sysCodePath.'img/', $src)
                );
                continue;
            }

            if (0 === strpos($src, '/app/upload/')) {
                $element->setAttribute(
                    'src',
                    str_replace('/app/upload/', $sysUploadPath, $src)
                );
                continue;
            }

            if (empty($courseInfo)) {
                continue;
            }

            if ('/' != api_get_path(REL_PATH)) {
                $oldSrcFixed = str_replace(
                    api_get_path(REL_PATH).'courses/'.$courseInfo['path'].'/document/',
                    '',
                    $src
                );

                // Try with the dirname if exists
                if ($oldSrcFixed == $src) {
                    if (file_exists($dirName.'/'.$src)) {
                        $documentPath = '';
                        $oldSrcFixed = $dirName.'/'.$src;
                    }
                }
            } else {
                if (false !== strpos($src, 'courses/'.$courseInfo['path'].'/document/')) {
                    $oldSrcFixed = str_replace('courses/'.$courseInfo['path'].'/document/', '', $src);
                } else {
                    // Try with the dirname if exists
                    if (file_exists($dirName.'/'.$src)) {
                        $documentPath = '';
                        $oldSrcFixed = $dirName.'/'.$src;
                    } else {
                        $documentPath = '';
                        $oldSrcFixed = $src;
                    }
                }
            }

            $element->setAttribute('src', $documentPath.$oldSrcFixed);
        }

        return $doc->saveHTML();
    }

    /**
     * Replaces icon tags in the HTML content with corresponding image paths.
     */
    public function replaceIconsWithImages(string $content): string
    {
        // Load icon images
        $checkboxOn = Display::return_icon('checkbox_on.png', null, null, ICON_SIZE_TINY);
        $checkboxOff = Display::return_icon('checkbox_off.png', null, null, ICON_SIZE_TINY);
        $radioOn = Display::return_icon('radio_on.png', null, null, ICON_SIZE_TINY);
        $radioOff = Display::return_icon('radio_off.png', null, null, ICON_SIZE_TINY);

        // Define replacements
        $replacements = [
            '/<i[^>]*class="[^"]*checkbox-marked-outline[^"]*"[^>]*><\/i>/i' => $checkboxOn,
            '/<i[^>]*class="[^"]*checkbox-blank-outline[^"]*"[^>]*><\/i>/i' => $checkboxOff,
            '/<i[^>]*class="[^"]*radiobox-marked[^"]*"[^>]*><\/i>/i' => $radioOn,
            '/<i[^>]*class="[^"]*radiobox-blank[^"]*"[^>]*><\/i>/i' => $radioOff,
        ];

        // Perform replacements
        foreach ($replacements as $pattern => $replacement) {
            $content = preg_replace($pattern, $replacement, $content);
        }

        return $content;
    }
}
