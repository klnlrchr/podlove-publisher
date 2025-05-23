<?php

namespace Podlove\Modules\Transcripts;

use Podlove\Model;
use Podlove\Model\Episode;
use Podlove\Modules\Contributors\Model\Contributor;
use Podlove\Modules\Transcripts\Model\Transcript;
use Podlove\Modules\Transcripts\Model\VoiceAssignment;
use Podlove\Webvtt\Parser;
use Podlove\Webvtt\ParserException;

class Transcripts extends \Podlove\Modules\Base
{
    protected $module_name = 'Transcripts';
    protected $module_description = 'Manage transcripts, show them on your site and in the web player.';
    protected $module_group = 'metadata';

    public function load()
    {
        add_action('podlove_delete_episode', [$this, 'on_delete_episode']);
        add_action('podlove_module_was_activated_transcripts', [$this, 'was_activated']);
        add_filter('podlove_episode_form_data', [$this, 'extend_episode_form'], 10, 2);

        add_filter('mime_types', [$this, 'ensure_vtt_mime_type_is_known'], 20);

        add_filter('podlove_player4_config', [$this, 'add_player_config'], 10, 2);
        add_filter('podlove_player5_config', [$this, 'add_player_config'], 10, 2);

        add_action('wp', [$this, 'serve_transcript_file']);

        add_action('podlove_xml_export', [$this, 'expandExportFile']);
        add_filter('podlove_import_jobs', [$this, 'expandImport']);

        // external assets
        add_action('podlove_asset_assignment_form', [$this, 'add_asset_assignment_form'], 10, 2);
        add_action('podlove_media_file_content_has_changed', [$this, 'handle_changed_media_file']);
        add_action('podlove_media_file_content_verified', [$this, 'handle_changed_media_file']);

        add_action('podlove_feeds_global_form', [$this, 'add_feeds_global_form'], 10, 1);

        add_filter('podlove_twig_file_loader', function ($file_loader) {
            $file_loader->addPath(implode(DIRECTORY_SEPARATOR, [\Podlove\PLUGIN_DIR, 'lib', 'modules', 'transcripts', 'twig']), 'transcripts');

            return $file_loader;
        });

        add_shortcode('podlove-transcript', [$this, 'transcript_shortcode']);

        \Podlove\Template\Episode::add_accessor(
            'transcript',
            ['\Podlove\Modules\Transcripts\TemplateExtensions', 'accessorEpisodeTranscript'],
            4
        );

        add_action('rest_api_init', [$this, 'api_init']);
        add_action('admin_notices', [$this, 'check_contributors_active']);

        $this->add_transcript_to_feed();
    }

    public function check_contributors_active()
    {
        if (!\Podlove\Modules\Base::is_active('contributors')) {
            $this->print_admin_notice();
        }
    }

    public function api_init()
    {
        $api = new REST_API();
        $api->register_routes();
        $api_v2 = new WP_REST_PodloveTranscripts_Controller();
        $api_v2->register_routes();
    }

    public function ensure_vtt_mime_type_is_known($mime_types)
    {
        if (!array_key_exists('vtt', $mime_types)) {
            $mime_types['vtt'] = 'text/vtt';
        }

        return $mime_types;
    }

    public function transcript_shortcode($args = [])
    {
        if (isset($args['post_id'])) {
            $post_id = $args['post_id'];
            unset($args['post_id']);
        } else {
            $post_id = get_the_ID();
        }

        $episode = Model\Episode::find_one_by_post_id($post_id);
        $episode = new \Podlove\Template\Episode($episode);

        return \Podlove\Template\TwigFilter::apply_to_html('@transcripts/transcript.twig', ['episode' => $episode]);
    }

    public function was_activated($module_name)
    {
        Transcript::build();
        VoiceAssignment::build();
    }

    public function on_delete_episode(Episode $episode)
    {
        Transcript::delete_for_episode($episode->id);
    }

    public function extend_episode_form($form_data, $episode)
    {
        $form_data[] = [
            'type' => 'callback',
            'key' => 'transcripts',
            'options' => [
                'callback' => function () {
                    ?>
  <div data-client="podlove" style="margin: 15px 0;">
    <podlove-transcripts></podlove-transcripts>
  </div>
<?php
                }
            ],
            'position' => 480,
        ];

        return $form_data;
    }

    /**
     * Import transcript from remote file.
     */
    public static function transcript_import_from_asset(Episode $episode)
    {
        $asset_assignment = Model\AssetAssignment::get_instance();

        if (!$transcript_asset = Model\EpisodeAsset::find_one_by_id($asset_assignment->transcript)) {
            return [
                'error' => sprintf(
                    __('No asset is assigned for transcripts yet. Fix this in %s', 'podlove-podcasting-plugin-for-wordpress'),
                    sprintf(
                        '%s%s%s',
                        '<a href="'.admin_url('admin.php?page=podlove_episode_assets_settings_handle').'" target="_blank">',
                        __('Episode Assets', 'podlove-podcasting-plugin-for-wordpress'),
                        '</a>'
                    )
                ),
            ];
        }

        if (!$transcript_file = Model\MediaFile::find_by_episode_id_and_episode_asset_id($episode->id, $transcript_asset->id)) {
            return ['error' => __('No transcript file is available for this episode.', 'podlove-podcasting-plugin-for-wordpress')];
        }

        $transcript = wp_remote_get($transcript_file->get_file_url());

        if (is_wp_error($transcript)) {
            return ['error' => $transcript->get_error_message()];
        }

        self::parse_and_import_webvtt($episode, $transcript['body']);

        return true;
    }

    public static function parse_webvtt($content)
    {
        $parser = new Parser();

        try {
            $result = $parser->parse($content);
        } catch (ParserException $e) {
            $error = 'Error parsing webvtt file: '.$e->getMessage();
            \Podlove\Log::get()->addError($error);

            return false;
        }

        return $result;
    }

    public static function parse_and_import_webvtt(Episode $episode, $content)
    {
        if (function_exists('mb_check_encoding') && !mb_check_encoding($content, 'UTF-8')) {
            \Podlove\AJAX\Ajax::respond_with_json(['error' => 'Error parsing webvtt file: must be UTF-8 encoded']);
        }

        $result = self::parse_webvtt($content);

        if ($result === false) {
            if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'podlove_transcript_import') {
                \Podlove\AJAX\Ajax::respond_with_json(['error' => 'Error parsing webvtt file']);
            }

            return;
        }

        Transcript::delete_for_episode($episode->id);

        foreach ($result['cues'] as $cue) {
            $line = new Transcript();
            $line->episode_id = $episode->id;
            $line->start = $cue['start'] * 1000;
            $line->end = $cue['end'] * 1000;
            $line->voice = $cue['voice'];
            $line->content = $cue['text'];
            $line->save();
        }

        $voices = array_unique(array_map(function ($cue) {
            return $cue['voice'];
        }, $result['cues']));

        foreach ($voices as $voice) {
            $contributor = Contributor::find_one_by_property('identifier', $voice);

            if (!VoiceAssignment::is_voice_set($episode->id, $voice) && $contributor) {
                $voice_assignment = new VoiceAssignment();
                $voice_assignment->episode_id = $episode->id;
                $voice_assignment->voice = $voice;
                $voice_assignment->contributor_id = $contributor->id;
                $voice_assignment->save();
            }
        }
    }

    public function serve_transcript_file()
    {
        $format = filter_input(INPUT_GET, 'podlove_transcript', FILTER_VALIDATE_REGEXP, [
            'options' => ['regexp' => '/^(json_podcastindex|json_grouped|json|webvtt|xml)$/'],
        ]);

        if (!$format) {
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            $post_id = intval($_GET['p'], 10);
        }

        if (!$post_id) {
            return;
        }

        if (!$episode = Model\Episode::find_or_create_by_post_id($post_id)) {
            return;
        }

        $renderer = new Renderer($episode);

        http_response_code(200);

        switch ($format) {
            case 'xml':
                header('Cache-Control: no-cache, must-revalidate');
                header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
                header('Content-Type: application/xml; charset=utf-8');
                echo $renderer->as_xml();

                exit;

                break;
            case 'webvtt':
                header('Cache-Control: no-cache, must-revalidate');
                header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
                header('Content-Type: text/vtt');
                echo $renderer->as_webvtt();

                exit;

                break;
            case 'json_podcastindex':
                header('Cache-Control: no-cache, must-revalidate');
                header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
                header('Content-type: application/json');
                echo $renderer->as_podcastindex_json();

                exit;

            case 'json':
            case 'json_grouped':
                header('Cache-Control: no-cache, must-revalidate');
                header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
                header('Content-type: application/json');
                $mode = ($format == 'json' ? 'flat' : 'grouped');
                echo $renderer->as_json($mode);

                exit;

                break;
        }
    }

    public function add_transcript_to_feed()
    {
        add_action('podlove_append_to_feed_entry', function ($podcast, $episode, $feed, $format) {
            $this->print_rss_feed_links($podcast, $episode);
        }, 10, 4);
    }

    public function add_player_config($config, $episode)
    {
        if (Transcript::exists_for_episode($episode->id)) {
            $url = add_query_arg('podlove_transcript', 'json', get_permalink($episode->post_id));
            $url = str_replace(home_url(), site_url(), $url);
            $config['transcripts'] = $url;
        }

        return $config;
    }

    public function add_asset_assignment_form($wrapper, $asset_assignment)
    {
        $transcript_options = [
            'manual' => __('Manual Upload', 'podlove-podcasting-plugin-for-wordpress'),
        ];

        $episode_assets = Model\EpisodeAsset::all();
        foreach ($episode_assets as $episode_asset) {
            $file_type = $episode_asset->file_type();
            if ($file_type && $file_type->extension === 'vtt') {
                $transcript_options[$episode_asset->id]
                = sprintf(__('Asset: %s', 'podlove-podcasting-plugin-for-wordpress'), esc_html($episode_asset->title));
            }
        }

        $wrapper->select('transcript', [
            'label' => __('Episode Transcript', 'podlove-podcasting-plugin-for-wordpress'),
            'options' => $transcript_options,
        ]);
    }

    public function add_feeds_global_form($wrapper)
    {
        $options = [
            'none' => __('Do not include in feed', 'podlove-podcasting-plugin-for-wordpress'),
            'generated' => __('Publisher Generated WebVTT (Default)', 'podlove-podcasting-plugin-for-wordpress'),
        ];

        foreach (Model\EpisodeAsset::all() as $asset) {
            $file_type = $asset->file_type();
            if ($file_type && in_array($file_type->extension, ['vtt', 'srt'])) {
                $options['asset_'.$asset->id] = sprintf(__('Asset: %s', 'podlove-podcasting-plugin-for-wordpress'), esc_html($asset->title));
            }
        }

        $wrapper->select('feed_transcripts', [
            'label' => __('Episode Transcripts', 'podlove-podcasting-plugin-for-wordpress'),
            'description' => __('How should episode transcripts be referenced in the RSS feed?', 'podlove-podcasting-plugin-for-wordpress'),
            'options' => $options,
            'default' => 'generated',
        ]);
    }

    /**
     * When vtt media file changes, reimport transcripts.
     *
     * @param mixed $media_file_id
     */
    public function handle_changed_media_file($media_file_id)
    {
        $media_file = Model\MediaFile::find_by_id($media_file_id);

        if (!$media_file) {
            return;
        }

        $asset = $media_file->episode_asset();

        if (!$asset) {
            return;
        }

        $file_type = $asset->file_type();

        if (!$file_type) {
            return;
        }

        if ($file_type->extension !== 'vtt') {
            return;
        }

        $this->transcript_import_from_asset($media_file->episode());
    }

    /**
     * Expands "Import/Export" module: export logic.
     */
    public function expandExportFile(\SimpleXMLElement $xml)
    {
        \Podlove\Modules\ImportExport\Export\PodcastExporter::exportTable($xml, 'transcripts', 'transcript', '\Podlove\Modules\Transcripts\Model\Transcript');
        \Podlove\Modules\ImportExport\Export\PodcastExporter::exportTable($xml, 'voice_assignments', 'voice_assignment', '\Podlove\Modules\Transcripts\Model\VoiceAssignment');
    }

    /**
     * Expands "Import/Export" module: import logic.
     *
     * @param mixed $jobs
     */
    public function expandImport($jobs)
    {
        $jobs[] = '\Podlove\Modules\Transcripts\Jobs\ImportTranscriptsJob';
        $jobs[] = '\Podlove\Modules\Transcripts\Jobs\ImportVoiceAssignmentsJob';

        return $jobs;
    }

    private function print_rss_feed_links($podcast, $episode)
    {
        if ($podcast->feed_transcripts == 'none') {
            return;
        }
        if ($podcast->feed_transcripts == 'generated') {
            if (!Transcript::exists_for_episode($episode->id)) {
                return;
            }

            $permalink = get_permalink($episode->post_id);
            $permalink = str_replace(home_url(), site_url(), $permalink);

            $url = add_query_arg('podlove_transcript', 'webvtt', $permalink);
            echo "\n\t\t".'<podcast:transcript url="'.esc_attr($url).'" type="text/vtt" />';

            $url = add_query_arg('podlove_transcript', 'json_podcastindex', $permalink);
            echo "\n\t\t".'<podcast:transcript url="'.esc_attr($url).'" type="application/json" />';

            return;
        }
        if (preg_match('/^asset_(?<id>\d+)$/', $podcast->feed_transcripts, $matches) === 1) {
            $asset_id = $matches['id'];
            $asset = Model\EpisodeAsset::find_by_id($asset_id);

            if (!$asset) {
                return;
            }

            $file = Model\MediaFile::find_by_episode_id_and_episode_asset_id($episode->id, $asset->id);

            if (!$file || !$file->active) {
                return;
            }

            $file_type = $asset->file_type();

            $url = $file->get_file_url();
            echo "\n\t\t".'<podcast:transcript url="'.esc_attr($url).'" type="'.esc_attr($file_type->mime_type).'" />';
        }

        // $url = add_query_arg('podlove_transcript', 'xml', get_permalink($episode->post_id));
        // $url = str_replace(home_url(), site_url(), $url);
        // echo "\n\t\t".'<podcast:transcript url="'.esc_attr($url).'" type="application/xml" />';
    }

    private function print_admin_notice()
    {
        ?>
      <div class="update-message notice notice-warning notice-alt">
        <p>
          <?php echo __('You need to activate the "Contributors" module to use transcripts.', 'podlove-podcasting-plugin-for-wordpress'); ?>
           <a href="<?php echo admin_url('admin.php?page=podlove_settings_modules_handle#contributors'); ?>"><?php echo __('Activate Now', 'podlove-podcasting-plugin-for-wordpress'); ?></a>
        </p>
      </div>
      <?php
    }
}
