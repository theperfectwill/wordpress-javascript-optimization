<?php
namespace O10n;

/**
 * Javascript Optimization Controller
 *
 * @package    optimization
 * @subpackage optimization/controllers
 * @author     PageSpeed.pro <info@pagespeed.pro>
 */
if (!defined('ABSPATH')) {
    exit;
}

class Js extends Controller implements Controller_Interface
{
    // module key refereces
    private $client_modules = array(
        'js',
        'jquery-stub'
    );

    // automatically load dependencies
    private $client_module_dependencies = array();

    private $replace = null; // replace in script
    private $script_cdn = null; // script CDN config
    private $http2_push = null; // HTTP/2 Server Push config

    private $diff_hash_prefix; // diff based hash prefix
    private $last_used_minifier; // last used minifier

    // extracted script elements
    private $script_elements = array();

    // load/render position
    private $load_position;
    private $rel_preload = false; // default rel="preload"
    private $async_exec = false; // default async exec

    private $async_filter; // filter for scripts
    private $async_filterConcat; // filter for concat groups
    private $async_filterType;

    private $localStorage = false; // default localStorage config

    /**
     * Load controller
     *
     * @param  Core       $Core Core controller instance.
     * @return Controller Controller instance.
     */
    public static function &load(Core $Core)
    {
        // instantiate controller
        return parent::construct($Core, array(
            'url',
            'env',
            'file',
            'http',
            'cache',
            'client',
            'json',
            'output',
            'tools',
            'proxy',
            'options'
        ));
    }

    /**
     * Setup controller
     */
    protected function setup()
    {
        // disabled
        if (!$this->env->is_optimization()) {
            return;
        }

        // add module definitions
        $this->client->add_module_definitions($this->client_modules, $this->client_module_dependencies);

        // extract scripts for processing?
        if ($this->options->bool(['js.minify','js.async','js.proxy'])) {

            // add script optimization client module
            $this->client->load_module('js', O10N_CORE_VERSION, $this->core->modules('js')->dir_path());

            // async loading
            if ($this->options->bool('js.async')) {
                $this->client->set_config('js', 'async', true);

                // jQuery stub
                if ($this->options->bool('js.async.jQuery_stub')) {
                    $this->client->load_module('jquery-stub', O10N_CORE_VERSION, $this->core->modules('js')->dir_path());
                }

                // rel="preload" based loading
                // @link https://www.w3.org/TR/2015/WD-preload-20150721/
                if ($this->options->bool('js.async.rel_preload')) {
                    $this->rel_preload = true;
                }

                // async download position
                $this->load_position = ($this->options->get('js.async.load_position') === 'timed') ? 'timed' : 'header';
                if ($this->load_position === 'timed') {
                    
                    // add timed exec module
                    $this->client->load_module('timed-exec');

                    // set load position
                    $this->client->set_config('js', 'load_position', $this->client->config_index('key', 'timing'));

                    // timing type
                    $timing_type = $this->options->get('js.async.load_timing.type');
                    switch ($timing_type) {
                        case "media":

                            // add responsive exec module
                            $this->client->load_module('responsive');
                        break;
                        case "inview":

                            // add inview exec module
                            $this->client->load_module('inview');
                        break;
                    }

                    // timing config
                    $this->load_timing = $this->timing_config($this->options->get('js.async.load_timing.*'));
                    if ($this->load_timing) {
                        $this->client->set_config('js', 'load_timing', $this->load_timing);
                    }
                }

                if ($this->options->bool('js.async.exec_timing.enabled')) {
                        
                    // add timed exec module
                    $this->client->load_module('timed-exec');

                    // timing type
                    $timing_type = $this->options->get('js.async.exec_timing.type');
                    switch ($timing_type) {
                        case "requestAnimationFrame":
                            $this->requestAnimationFrame = false;
                        break;
                        case "media":

                            // add responsive exec module
                            $this->client->load_module('responsive');
                        break;
                        case "inview":

                            // add inview exec module
                            $this->client->load_module('inview');
                        break;
                    }

                    // timing config
                    $this->exec_timing = $this->timing_config($this->options->get('js.async.exec_timing.*'));
                    if ($this->exec_timing) {
                        $this->client->set_config('js', 'exec_timing', $this->exec_timing);
                    }
                }

                // async exec (default)
                if ($this->options->bool('js.async.exec')) {
                    $this->async_exec = $this->options->get('js.async.exec.*');
                    switch ($this->async_exec['type']) {
                        case "domReady":
                            $keys = array();
                        break;
                        case "requestAnimationFrame":
                            $keys = array('frame' => 'JSONKEY');
                        break;
                        case "requestIdleCallback":
                            $keys = array('timeout' => 'JSONKEY','setTimeout' => 'JSONKEY');
                        break;
                        case "inview":

                            // load inview module
                            $this->client->load_module('inview');

                            $keys = array('selector' => 'JSONKEY','offset' => 'JSONKEY');
                        break;
                        case "media":

                            // load responsive module
                            $this->client->load_module('responsive');

                            $keys = array('media' => 'JSONKEY');
                        break;
                    }
                    $async_exec_config = $this->client->config_array_data($this->async_exec, $keys);
                    if (!empty($async_exec_config)) {
                        $this->client->set_config('js', 'async_exec', array(
                            $this->client->config_index('jsonkey_'.$this->async_exec['type']),
                            $async_exec_config
                        ));
                    } else {
                        $this->client->set_config('js', 'async_exec', $this->client->config_index('jsonkey_'.$this->async_exec['type']));
                    }
                }
                
                // localStorage cache
                if ($this->options->bool('js.async.localStorage')) {

                    // load client module
                    $this->client->load_module('localstorage');

                    // set enabled state
                    $this->client->set_config('js', 'localStorage', true);

                    // localStorage config
                    $this->localStorage = array();

                    $config_keys = array('max_size','expire','update_interval');
                    foreach ($config_keys as $key) {
                        $this->localStorage[$key] = $this->options->get('js.async.localStorage.' . $key);
                        if ($this->localStorage[$key]) {
                            $this->client->set_config('js', 'localStorage_' . $key, $this->localStorage[$key]);
                        }
                    }

                    if ($this->options->bool('js.async.localStorage.head_update')) {
                        $this->localStorage['head_update'] = 1;
                        $this->client->set_config('js', 'localStorage_head_update', 1);
                    }
                }
            }

            // add filter for HTML output
            add_filter('o10n_html_pre', array( $this, 'process_html' ), 10, 1);
        }
    }

    /**
     * Minify the markeup given in the constructor
     *
     * @param  string $HTML Reference to HTML to process
     * @return string Filtered HTML
     */
    final public function process_html($HTML)
    {
        // verify if empty
        if ($HTML === '') {
            return $HTML; // no HTML
        }

        // extract <script> elements from HTML
        $this->extract($HTML);

        // no script elements, skip
        if (empty($this->script_elements)) {
            return $HTML;
        }

        // debug modus
        $debug = (defined('O10N_DEBUG') && O10N_DEBUG);

        // script urls
        $script_urls = array();

        // load async
        $async = $this->options->bool('js.async');
        if ($async) {

            // client config
            $async_scripts = array();

            // async load position
            $async_position = ($this->options->get('js.async.position') === 'footer') ? 'foot' : 'critical-css';
        }

        // concatenation
        $concat = $this->options->bool('js.minify') && $this->options->bool('js.minify.concat');

        // rel="preload"
        if ($this->rel_preload) {
            
            // rel="preload" position
            $this->rel_preload_position = ($async_position) ? $async_position : 'critical-css';
        }

        // concatenation settings
        if ($concat) {
            
            // concatenate filter
            if ($this->options->bool('js.minify.concat.filter')) {
                $concat_filter = $this->sanitize_filter($this->options->get('js.minify.concat.filter.config'));
            } else {
                $concat_filter = false;
            }

            // concatenate
            $concat_groups = array();
            $concat_group_settings = array();
        }


        // walk css elements
        foreach ($this->script_elements as $n => $script) {

            // concatenate
            if ($concat && (
                isset($script['inline']) // inline
                || (isset($script['minified']) && $script['minified']) // minified source
            )) {

                // concat group filter
                if ($concat_filter) {

                    // set to false (skip concat) if concatenation is excluded by default
                    $concat_group = ($this->options->get('js.minify.concat.filter.type', 'include') !== 'include') ? false : 'global';

                    // apply group filter
                    $this->apply_filter($concat_group, $concat_group_settings, $script['tag'], $concat_filter);
                } else {
                    $concat_group = 'global';
                }

                // include script in concatenation
                if ($concat_group) {

                    // initiate group
                    if (!isset($concat_groups[$concat_group])) {

                        // scripts in group
                        $concat_groups[$concat_group] = array();

                        // group settings
                        if (!isset($concat_group_settings[$concat_group])) {
                            $concat_group_settings[$concat_group] = array();
                        }

                        $concat_group_key = (isset($concat_group_settings[$concat_group]['group']) && isset($concat_group_settings[$concat_group]['group']['key'])) ? $concat_group_settings[$concat_group]['group']['key'] : 'global';

                        // load async by default
                        $concat_group_settings[$concat_group]['async'] = $this->options->bool('js.async');

                        // apply async filter
                        if (!empty($this->async_filterConcat)) {

                            // apply filter to key
                            $asyncConfig = $this->tools->filter_config_match($concat_group_key, $this->async_filterConcat, $this->async_filterType);

                            // filter config object
                            if ($asyncConfig && is_array($asyncConfig)) {

                                // async enabled by filter
                                if (!isset($asyncConfig['async']) || $asyncConfig['async']) {
                                    $concat_group_settings[$concat_group]['async'] = $this->options->bool('js.async');

                                    // custom load position
                                    if (isset($asyncConfig['load_position']) && $asyncConfig['load_position'] !== $this->load_position) {
                                        $concat_group_settings[$concat_group]['load_position'] = $asyncConfig['load_position'];
                                    }

                                    // async exec
                                    if (isset($asyncConfig['async_exec'])) {
                                        $concat_group_settings[$concat_group]['async_exec'] = $asyncConfig['async_exec'];
                                    }

                                    // abide dependencies
                                    if (isset($asyncConfig['abide'])) {
                                        $concat_group_settings[$concat_group]['abide'] = $asyncConfig['abide'];
                                    }

                                    // try catch
                                    if (isset($asyncConfig['trycatch'])) {
                                        $concat_group_settings[$concat_group]['trycatch'] = $asyncConfig['trycatch'];
                                    }

                                    // custom rel_preload
                                    if (isset($asyncConfig['rel_preload']) && $asyncConfig['rel_preload'] !== $this->rel_preload) {
                                        $concat_group_settings[$concat_group]['rel_preload'] = $asyncConfig['rel_preload'];
                                    }

                                    // custom localStorage
                                    if (isset($asyncConfig['localStorage'])) {
                                        if ($asyncConfig['localStorage'] === false) {
                                            $concat_group_settings[$concat_group]['localStorage'] = false;
                                        } elseif ($asyncConfig['localStorage'] === true && $this->localStorage) {
                                            $concat_group_settings[$concat_group]['localStorage'] = $this->localStorage;
                                        } else {
                                            $concat_group_settings[$concat_group]['localStorage'] = $asyncConfig['localStorage'];
                                        }
                                    }
                                } else {

                                    // do not load async
                                    $concat_group_settings[$concat_group]['async'] = false;
                                }
                            } elseif ($asyncConfig === true) {

                                // include by default
                                $concat_group_settings[$concat_group]['async'] = true;
                            }
                        }
                    }

                    $async_exec = (isset($script['async_exec'])) ? $script['async_exec'] : null;

                    // inline <style>
                    if (isset($script['inline'])) {
                        $hash = md5($script['text']);
                        $concat_groups[$concat_group][] = array(
                            'inline' => true,
                            'hash' => $hash,
                            'cache_hash' => $hash,
                            'tag' => $script['tag'],
                            'text' => $script['text'],
                            'async_exec' => $async_exec,
                            'position' => count($async_scripts),
                            'element' => $script
                        );
                    } else {
                        // minified script
                        $concat_groups[$concat_group][] = array(
                            'hash' => $script['minified'][0] . ':' . $script['minified'][1],
                            'cache_hash' => $script['minified'][0],
                            'tag' => $script['tag'],
                            'src' => $script['src'],
                            'async_exec' => $async_exec,
                            'position' => count($async_scripts),
                            'element' => $script
                        );
                    }

                    // remove script from HTML
                    $this->output->add_search_replace($script['tag'], '');

                    // maintain position index
                    $async_scripts[] = false;

                    // maintain position index
                    $script_urls[] = false;

                    continue 1; // next script
                }
            } // concat end

            // inline <style> without concatenation, ignore
            if (isset($script['inline'])) {
                continue 1; // next script
            }
            
            // load async
            if ($async && $script['async']) {
                
                // config
                $load_position = (isset($script['load_position'])) ? $script['load_position'] : $this->load_position;
                $rel_preload = (isset($script['rel_preload'])) ? $script['rel_preload'] : $this->rel_preload;
                $async_exec = (isset($script['async_exec'])) ? $script['async_exec'] : null;
                $abide = (isset($script['abide'])) ? $script['abide'] : false;

                // minified script
                if (isset($script['minified']) && $script['minified']) {
                    // hash type
                    $script_type = 'src';

                    // script path
                    $script_hash = str_replace('/', '', $this->cache->hash_path($script['minified'][0]) . substr($script['minified'][0], 6));

                    // script url
                    $script_url = $this->url_filter($this->cache->url('js', 'src', $script['minified'][0]));
                } else {

                    // proxy hash
                    if (isset($script['proxy']) && $script['proxy']) {

                        // hash type
                        $script_type = 'proxy';

                        // script path
                        $script_hash = str_replace('/', '', $this->cache->hash_path($script['proxy']) . substr($script['proxy'], 6));

                        // script url
                        $script_url = $this->url_filter($script['src']);
                    } else {

                        // hash type
                        $script_type = 'url';

                        // script url
                        $script_hash = $script_url = $this->url_filter($script['src']);
                    }
                }

                // add script to async list
                $async_script = array(
                    'type' => $script_type,
                    'url' => $script_hash,
                    'original_url' => $script['src'],
                    'load_position' => $load_position,
                    'async_exec' => $async_exec,
                    'abide' => $abide
                );
                if (isset($script['localStorage'])) {
                    $async_script['localStorage'] = $script['localStorage'];
                }
                $async_scripts[] = $async_script;

                // rel="preload" or <noscript>
                if ($rel_preload || $noscript) {

                    // add script to url list
                    $script_urls[] = array(
                        'url' => $script_url,
                        'rel_preload' => $rel_preload,
                        'load_position' => $load_position,
                        'async_exec' => $async_exec,
                        'abide' => $abide
                    );
                } else {
                    $script_urls[] = false;
                }

                // remove script from HTML
                $this->output->add_search_replace($script['tag'], '');
            } else {
                if (isset($script['minified']) && $script['minified']) {

                    // minified URL
                    $script['src'] = $this->cache->url('js', 'src', $script['minified'][0]);
                    $script['replaceSrc'] = true;
                }

                // apply CDN
                $filteredSrc = $this->url_filter($script['src']);
                if ($filteredSrc !== $script['src']) {
                    $script['src'] = $filteredSrc;
                    $script['replaceSrc'] = true;
                }

                // replace src in HTML
                if (isset($script['replaceSrc'])) {

                    // replace src in tag
                    $this->output->add_search_replace($script['tag'], $this->src_regex($script['tag'], $script['src']));
                }
            }
        }

        // process concatenated scripts
        if ($concat) {

            // concat using minify
            $concat_minify = $this->options->bool('js.concat.minify');

            // wrap scripts in try {} catch(e) {}
            $concat_trycatch = $this->options->bool('js.minify.concat.trycatch');

            foreach ($concat_groups as $concat_group => $scripts) {

                // position to load concatenated script
                $async_insert_position = 0;

                // script hashes
                $concat_hashes = array();

                // add group key to hash
                if ($concat_group_settings && isset($concat_group_settings[$concat_group]['group']) && isset($concat_group_settings[$concat_group]['group']['key'])) {
                    $concat_hashes[] = $concat_group_settings[$concat_group]['group']['key'];
                }

                // add script hashes
                foreach ($scripts as $script) {
                    $concat_hashes[] = $script['hash'];
                    if ($script['position'] > $async_insert_position) {
                        $async_insert_position = $script['position'];
                    }
                }

                // insert after last script in concatenated group
                $async_insert_position++;

                // calcualte hash from source files
                $urlhash = md5(implode('|', $concat_hashes));

                // load from cache
                if ($this->cache->exists('js', 'concat', $urlhash)) {

                    // preserve cache file based on access
                    $this->cache->preserve('js', 'concat', $urlhash, (time() - 3600));

                    $contact_original_urls = array();
                    foreach ($scripts as $script) {
                        if (isset($script['inline'])) {
                            $script_filename = 'inline-' . $script['hash'];
                            $contact_original_urls[] = $script_filename;
                        } else {
                            $contact_original_urls[] = $script['src'];
                        }

                        if (isset($script['async_exec']) && $script['async_exec']) {
                            switch ($script['async_exec']['type']) {
                                case "inview":
                                    if (isset($script['async_exec']['selector'])) {
                                        // load inview module
                                        $this->client->load_module('inview');
                                    }
                                break;
                                case "media":
                                    if (isset($script['async_exec']['selector'])) {
                                        // load responsive module
                                        $this->client->load_module('responsive');
                                    }
                                break;
                            }
                        }
                    }
                } else {

                    // concatenate scripts
                    $concat_sources = array();
                    $contact_original_urls = array();
                    foreach ($scripts as $script) {
                        if (isset($script['inline'])) {
                            // get source
                            $source = $script['text'];
                            $script_filename = 'inline-' . $script['hash'];
                            $contact_original_urls[] = $script_filename;
                        } else {
                            
                            // get source from cache
                            $source = $this->cache->get('js', 'src', $script['cache_hash']);
                            $script_filename = $this->extract_filename($script['src']);
                            $contact_original_urls[] = $script['src'];
                        }

                        // empty, ignore
                        if (!$source) {
                            continue 1;
                        }

                        if (isset($script['async_exec']) && $script['async_exec']) {
                            switch ($script['async_exec']['type']) {
                                case "domReady":
                                    $source = 'o10n.ready(function(){' . $source . '});';
                                break;
                                case "requestAnimationFrame":
                                    $frame = (isset($script['async_exec']['frame'])) ? $script['async_exec']['frame'] : 1;
                                    $source = 'o10n.raf(function(){' . $source . '},'.$frame.');';
                                break;
                                case "requestIdleCallback":
                                    $timeout = (isset($script['async_exec']['timeout'])) ? $script['async_exec']['timeout'] : 'false';
                                    $setTimeout = (isset($script['async_exec']['setTimeout'])) ? $script['async_exec']['setTimeout'] : 'false';
                                    $source = 'o10n.idle(function(){' . $source . '},'.$timeout.','.$setTimeout.');';
                                break;
                                case "inview":
                                    if (isset($script['async_exec']['selector'])) {
                                        $offset = (isset($script['async_exec']['offset']) && is_numeric($script['async_exec']['offset'])) ? (string)$script['async_exec']['offset'] : 'false';

                                        // load inview module
                                        $this->client->load_module('inview');

                                        $source = 'o10n.inview('.json_encode($script['async_exec']['selector']).','.$offset.',function(){' . $source . '});';
                                    }

                                break;
                                case "media":
                                    if (isset($script['async_exec']['media'])) {

                                        // load responsive module
                                        $this->client->load_module('responsive');

                                        $source = 'o10n.media('.json_encode($script['async_exec']['media']).',function(){' . $source . '});';
                                    }

                                break;
                            }
                        }

                        // wrap in in try {} catch(e) {}
                        if ($concat_trycatch) {
                            $source = 'try{' . $source . '}catch(e){if(console&&console.error){console.error(e);}}';
                        }

                        // concat source config
                        $concat_sources[$script_filename] = array();

                        // remove sourceMap references
                        $sourcemapIndex = strpos($source, '/*# sourceMappingURL');
                        while ($sourcemapIndex !== false) {
                            $sourcemapEndIndex = strpos($source, '*/', $sourcemapIndex);
                            $source = substr_replace($source, '', $sourcemapIndex, (($sourcemapEndIndex - $sourcemapIndex) + 2));
                            $sourcemapIndex = strpos($source, '/*# sourceMappingURL');
                        }

                        // script source
                        $concat_sources[$script_filename]['text'] = $source;

                        // create source map
                        if (!isset($script['inline']) && $this->options->bool('js.minify.clean-js.sourceMap')) {
                            $map = $this->cache->get('js', 'src', $script['cache_hash'], false, false, '.js.map');
                            $concat_sources[$script_filename]['map'] = $map;
                        }
                    }

                    // use minify?
                    $concat_group_minify = (isset($concat_group_settings[$concat_group]['minify'])) ? $concat_group_settings[$concat_group]['minify'] : $concat_minify;
                    $concat_group_key = (isset($concat_group_settings[$concat_group]['group']) && isset($concat_group_settings[$concat_group]['group']['key'])) ? $concat_group_settings[$concat_group]['group']['key'] : false;

                    // concatenate using minify
                    if ($concat_group_minify) {

                        // target src cache dir of concatenated scripts for URL rebasing
                        $target_src_dir = $this->file->directory_url('js/0/1/', 'cache', true);

                        // create concatenated file using minifier
                        try {
                            $minified = $this->minify($concat_sources, $target_src_dir);
                        } catch (Exception $err) {
                            $minified = false;
                        }
                    } else {
                        $minified = false;
                    }

                    if ($minified) {

                        // apply filters
                        $minified['text'] = $this->minified_css_filters($minified['text']);

                        // header
                        $minified['text'] .= "\n/* ";

                        // group title
                        if ($concat_group_settings) {
                            if (isset($concat_group_settings[$concat_group]['title'])) {
                                $minified['text'] .= $concat_group_settings[$concat_group]['title'] . "\n ";
                            }
                        }

                        $minified['text'] .= "@concat";

                        if ($concat_group_key) {
                            $minified['text'] .= " " . $concat_group_key;
                        }

                        if ($this->last_used_minifier) {
                            $minified['text'] .= " @min " . $this->last_used_minifier;
                        }

                        $minified['text'] .= " */";

                        // store cache file
                        $cache_file_path = $this->cache->put('js', 'concat', $urlhash, $minified['text'], $concat_group_key);

                        //return $HTML = var_export(file_get_contents($cache_file_path), true);

                        // add link to source map
                        if (isset($minified['sourcemap'])) {

                            // add link to script
                            $minified['text'] .= "\n/*# sourceMappingURL=".basename($cache_file_path).".map */";

                            // update script cache
                            try {
                                $this->file->put_contents($cache_file_path, $minified['text']);
                            } catch (\Exception $e) {
                                throw new Exception('Failed to store script ' . $this->file->safe_path($cache_file_path) . ' <pre>'.$e->getMessage().'</pre>', 'config');
                            }

                            // apply filters
                            $minified['sourcemap'] = $this->minified_sourcemap_filter($minified['sourcemap']);

                            // store source map
                            try {
                                $this->file->put_contents($cache_file_path . '.map', $minified['sourcemap']);
                            } catch (\Exception $e) {
                                throw new Exception('Failed to store script source map ' . $this->file->safe_path($cache_file_path . '.map') . ' <pre>'.$e->getMessage().'</pre>', 'config');
                            }
                        }
                    } else {

                        // minification failed, simply join files
                        $script = array();
                        foreach ($concat_sources as $source) {
                            $script[] = $source['text'];
                        }

                        // store cache file
                        $this->cache->put('js', 'concat', $urlhash, implode(' ', $script), $concat_group_key);
                    }
                }

                // load async?
                $concat_group_async = (isset($concat_group_settings[$concat_group]['async'])) ? $concat_group_settings[$concat_group]['async'] : $this->options->bool('js.async');

                // config
                $load_position = (isset($concat_group_settings[$concat_group]['load_position'])) ? $concat_group_settings[$concat_group]['load_position'] : $this->load_position;
                $rel_preload = (isset($concat_group_settings[$concat_group]['rel_preload'])) ? $concat_group_settings[$concat_group]['rel_preload'] : $this->rel_preload;
                $async_exec = (isset($concat_group_settings[$concat_group]['async_exec'])) ? $concat_group_settings[$concat_group]['async_exec'] : null;
                $abide = (isset($concat_group_settings[$concat_group]['abide'])) ? $concat_group_settings[$concat_group]['abide'] : false;

                // load async (concatenated script)
                if ($concat_group_async) {

                    // add script to async list
                    $async_script = array(
                        'type' => 'concat',
                        'url' => $this->async_hash_path('js', $urlhash),
                        'original_url' => $contact_original_urls,
                        'load_position' => $load_position,
                        'async_exec' => $async_exec,
                        'abide' => $abide
                    );

                    if (isset($concat_group_settings[$concat_group]['localStorage'])) {
                        $async_script['localStorage'] = $concat_group_settings[$concat_group]['localStorage'];
                    }

                    // add to position of last script in concatenated script
                    array_splice($async_scripts, $async_insert_position, 0, array($async_script));

                    // config
                    if ($rel_preload) {

                        // add to position of last script in concatenated script
                        array_splice($script_urls, $async_insert_position, 0, array(array(
                            'url' => $this->url_filter($this->cache->url('js', 'concat', $urlhash)),
                            'rel_preload' => $rel_preload,
                            'load_position' => $load_position,
                            'async_exec' => $async_exec,
                            'abide' => $abide
                        )));
                    }
                } else {
                    
                    // position in document
                    $position = ($load_position === 'footer') ? 'footer' : 'client';

                    // concat URL
                    $script_url = $this->url_filter($this->cache->url('js', 'concat', $urlhash));

                    // include script in HTML
                    $this->client->after($position, '<script src="'.esc_url($script_url).'"'.(($media && $media !== 'all') ? ' media="'.esc_attr($media).'"' : '').'></script>');
                }
            }
        }

        // load async
        if ($async) {
            if (!empty($async_scripts)) {
                
                // async list
                $async_list = array();
                $async_ref_list = array(); // debug ref list

                // concat index list
                $concat_index = array();

                // type prefixes
                $hash_type_prefixes = array(
                    'url' => 1,
                    'proxy' => 2
                );

                foreach ($async_scripts as $script) {
                    if (!$script) {
                        continue;
                    }

                    // load position
                    $load_position = ($script['load_position'] && $script['load_position'] !== $this->load_position) ? $script['load_position'] : false;
                    if ($load_position) {
                        $load_position = ($load_position === 'footer') ? 1 : 0;
                    }

                    // async exec
                    $async_exec = ($script['async_exec'] && $script['async_exec'] !== $this->async_exec) ? $script['async_exec'] : false;
                    if ($async_exec) {
                        switch ($async_exec['type']) {
                            case "domReady":
                                $keys = array();
                            break;
                            case "requestAnimationFrame":
                                $keys = array('frame' => 'JSONKEY');
                            break;
                            case "requestIdleCallback":
                                $keys = array('timeout' => 'JSONKEY','setTimeout' => 'JSONKEY');
                            break;
                            case "inview":

                                // load inview module
                                $this->client->load_module('inview');

                                $keys = array('selector' => 'JSONKEY','offset' => 'JSONKEY');
                            break;
                            case "media":

                                // load responsive module
                                $this->client->load_module('inview');

                                $keys = array('media' => 'JSONKEY');
                            break;
                        }

                        $async_exec_config = $this->client->config_array_data($async_exec, $keys);
                        $async_exec = array($this->client->config_index('jsonkey_'.$async_exec['type']));
                        if (!empty($async_exec_config)) {
                            $async_exec[] = $async_exec_config;
                        }
                    }

                    // hash type prefix
                    $hash_type_prefix = (isset($hash_type_prefixes[$script['type']])) ? $hash_type_prefixes[$script['type']] : false;

                    // add concat index position
                    if ($script['type'] === 'concat') {
                        $concat_index[] = count($async_list);
                    }

                    // async script object
                    $async_script = array();

                    // add hash prefix
                    if ($hash_type_prefix) {
                        $async_script[] = $hash_type_prefix;
                    }

                    // script URL or hash
                    $async_script[] = $script['url'];

                    // script media
                    $media_set = $load_set = $async_exec_set = false;

                    // load config
                    if ($load_position !== false) {
                        if (!$media_set) {
                            $async_script[] = '__O10N_NULL__';
                            $media_set = true;
                        }
                        $async_script[] = $load_position;
                        $load_set = true;
                    }

                    // async exec config
                    if ($async_exec !== false) {
                        if (!$media_set) {
                            $async_script[] = '__O10N_NULL__';
                            $media_set = true;
                        }
                        if (!$load_set) {
                            $async_script[] = '__O10N_NULL__';
                            $load_set = true;
                        }
                        $async_script[] = $async_exec;
                        $async_exec_set = true;
                    }

                    // custom localStorage config
                    if (isset($script['localStorage'])) {
                        if (!$media_set) {
                            $async_script[] = '__O10N_NULL__';
                            $media_set = true;
                        }
                        if (!$load_set) {
                            $async_script[] = '__O10N_NULL__';
                            $load_set = true;
                        }
                        if (!$async_exec_set) {
                            $async_script[] = '__O10N_NULL__';
                            $async_exec_set = true;
                        }
                        if (is_array($script['localStorage'])) {
                            $async_script[] = $this->client->config_array_data($script['localStorage'], array(
                                'max_size' => 'JSONKEY',
                                'update_interval' => 'JSONKEY',
                                'head_update' => 'JSONKEY',
                                'expire' => 'JSONKEY'
                            ));
                        } else {
                            $async_script[] = ($script['localStorage']) ? 1 : 0;
                        }
                    }

                    // add to async list
                    $async_list[] = $async_script;

                    if ($debug) {
                        $async_ref_list[$script['url']] = $script['original_url'];
                    }
                }

                //return var_export($async_ref_list, true);
                // add async list to client
                $this->client->set_config('js', 'async', $async_list);

                // add references
                if ($debug) {
                    $this->client->set_config('js', 'debug_ref', $async_ref_list);
                }

                // add concat index to client
                if (count($async_list) === count($concat_index)) {
                    $this->client->set_config('js', 'concat', 1); // concat only
                } else {
                    $this->client->set_config('js', 'concat', $concat_index); // concat indexes
                }
            }
        }

        // add rel="preload"

        foreach ($script_urls as $script) {
            if (!$script) {
                continue;
            }

            // rel="preload" as="script"
            if (isset($script['rel_preload']) && $script['rel_preload']) {
                if (isset($script['async_exec']) && !is_null($script['async_exec'])) {
                    $async_exec = $script['async_exec'];
                } else {
                    $async_exec = $this->async_exec;
                }

                // position in document
                $position = ($script['load_position'] === 'footer') ? 'footer' : 'critical-css';

                if ($async_exec && $async_exec['type'] === 'media' && isset($async_exec['media'])) {
                    $media = $async_exec['media'];
                } else {
                    $media = false;
                }

                $this->client->after($position, '<link rel="preload" as="script" href="'.esc_url($script['url']).'"'.(($media) ? ' media="'.esc_attr($media).'"' : '').'>');
            }
        }
        
        return $HTML;
    }

    /**
     * Search and replace strings in script
     *
     * To enable different minification settings per page, any settings that modify the script before minification should be used in the hash.
     *
     * @param  string $resource Resource
     * @return string MD5 hash for resource
     */
    final public function js_filters($script)
    {

        // initiate search & replace config
        if ($this->replace === null) {

            // script Search & Replace config
            $replace = $this->options->get('js.replace');
            if (!$replace || empty($replace)) {
                $this->replace = false;
            } else {
                $this->replace = array(
                    'search' => array(),
                    'replace' => array(),
                    'search_regex' => array(),
                    'replace_regex' => array()
                );
                
                foreach ($replace as $object) {
                    if (!isset($object['search']) || trim($object['search']) === '') {
                        continue;
                    }

                    if (isset($object['regex']) && $object['regex']) {
                        $this->replace['search_regex'][] = $object['search'];
                        $this->replace['replace_regex'][] = $object['replace'];
                    } else {
                        $this->replace['search'][] = $object['search'];
                        $this->replace['replace'][] = $object['replace'];
                    }
                }
            }
        }

        // apply search & replace filter
        if ($this->replace) {

            // apply string search & replace
            if (!empty($this->replace['search'])) {
                $script = str_replace($this->replace['search'], $this->replace['replace'], $script);
            }

            // apply regular expression search & replace
            if (!empty($this->replace['search_regex'])) {
                try {
                    $script = @preg_replace($this->replace['search_regex'], $this->replace['replace_regex'], $script);
                } catch (\Exception $err) {
                    // @todo log error
                }
            }
        }

        return $script;
    }

    /**
     * Extract scripts from HTML
     *
     * @param  string $HTML HTML source
     * @return array  Extracted scripts
     */
    final private function extract($HTML)
    {

        // extracted script elements
        $this->script_elements = array();

        // minify
        $minify = $this->options->bool('js.minify');

        // async
        $async = $this->options->bool('js.async');

        // proxy
        $proxy = $this->options->bool('js.proxy');

        // concat
        $concat = $minify && $this->options->bool('js.minify.concat');

        if ($concat) {
            $concat_inline = $this->options->bool('js.minify.concat.inline');

            // filter
            if ($this->options->bool('js.minify.concat.inline.filter')) {
                $concat_inline_filterType = $this->options->get('js.minify.concat.inline.filter.type');
                $concat_inline_filter = $this->options->get('js.minify.concat.inline.filter.' . $concat_inline_filterType);
                if (empty($concat_inline_filter)) {
                    $concat_inline_filter = false;
                }
            } else {
                $concat_inline_filter = false;
            }
        } else {
            $concat_inline = false;
        }

        // replace href
        $replaceSrc = false;

        // pre url filter
        if ($this->options->bool('js.url_filter')) {
            $url_filter = $this->options->get('js.url_filter.config');
            if (empty($url_filter)) {
                $url_filter = false;
            }
        } else {
            $url_filter = false;
        }

        // minify filter
        if ($minify && $this->options->bool('js.minify.filter')) {
            $minify_filterType = $this->options->get('js.minify.filter.type');
            $minify_filter = $this->options->get('js.minify.filter.' . $minify_filterType);
            if (empty($minify_filter)) {
                $minify_filter = false;
            }
        } else {
            $minify_filter = false;
        }

        // async filter
        if ($async && $this->options->bool('js.async.filter')) {
            $this->async_filterType = $this->options->get('js.async.filter.type');
            $this->async_filter = $this->options->get('js.async.filter.config');
            if (empty($this->async_filter)) {
                $this->async_filter = false;
            } else {
                $this->async_filterConcat = array_filter($this->async_filter, function ($filter) {
                    return (isset($filter['match_concat']));
                });
                if (!empty($this->async_filterConcat)) {
                    $this->async_filter = array_filter($this->async_filter, function ($filter) {
                        return (!isset($filter['match_concat']));
                    });
                }
            }
        } else {
            $this->async_filter = false;
        }

        // proxy filter
        if ($proxy) {
            $proxy_filter = $this->options->get('js.proxy.include');
            if (empty($proxy_filter)) {
                $proxy_filter = false;
            }
        } else {
            $proxy_filter = false;
        }

        // script regex
        // @todo optimize
        $script_regex = '#(<\!--\[if[^>]+>\s*)?<script([^>]*)>((.*?)</script>)?#smi';
        
        if (preg_match_all($script_regex, $HTML, $out)) {
            foreach ($out[0] as $n => $scriptTag) {

                // conditional, skip
                if (trim($out[1][$n]) !== '') {
                    continue 1;
                }

                // script
                $script = array(
                    'tag' => $scriptTag,
                    'minify' => $minify,
                    'async' => $async
                );

                // inline script text
                $text = $out[4][$n];
                if ($text) {
                    $text = trim($text);
                }

                // attributes
                $attributes = trim($out[2][$n]);

                // verify if tag contains src
                $src = strpos($attributes, 'src');
                if ($src !== false) {
                    // extract src using regular expression
                    $src = $this->src_regex($attributes);
                    if (!$src) {
                        continue 1;
                    }

                    $script['src'] = $src;
                    if ($text) {
                        $script['text'] = $text;
                    }
                } elseif ($concat_inline && $text) {

                    // inline script

                    // apply script filter pre processing
                    $filteredText = apply_filters('o10n_script_text_pre', $text, $script['tag']);

                    // ignore script
                    if ($filteredText === 'ignore') {
                        continue 1;
                    }

                    // delete script
                    if ($filteredText === 'delete') {
                        
                        // delete from HTML
                        $this->output->add_search_replace($script['tag'], '');
                        continue 1;
                    }

                    // replace script
                    if ($filteredText !== $text) {
                        $text = $filteredText;
                    }

                    // apply inline filter
                    if ($concat_inline_filter) {
                        $do_concat = $this->tools->filter_list_match($script['tag'], $concat_inline_filterType, $concat_inline_filter);
                        if (!$do_concat) {
                            continue 1;
                        }
                    }

                    $script['inline'] = true;
                    $script['text'] = $text;
                } else {
                    // do not process
                    continue 1;
                }

                // apply pre url filter
                if ($url_filter) {
                    foreach ($url_filter as $rule) {
                        if (!is_array($rule)) {
                            continue 1;
                        }

                        // match
                        $match = true;
                        if (isset($rule['regex']) && $rule['regex']) {
                            try {
                                if (!preg_match($rule['url'], $src)) {
                                    $match = false;
                                }
                            } catch (\Exception $err) {
                                $match = false;
                            }
                        } else {
                            if (strpos($src, $rule['url']) === false) {
                                $match = false;
                            }
                        }
                        if (!$match) {
                            continue 1;
                        }

                        // ignore script
                        if (isset($rule['ignore'])) {
                            continue 2; // next script
                        }

                        // delete script
                        if (isset($rule['delete'])) {
                            
                            // delete from HTML
                            $this->output->add_search_replace($script['tag'], '');
                            continue 2; // next script
                        }

                        // replace script
                        if (isset($rule['replace'])) {
                            $script['src'] = $rule['replace'];
                            $script['replaceSrc'] = true;
                        }
                    }
                }

                // apply custom script filter pre processing
                $filteredSrc = apply_filters('o10n_script_src_pre', $src, $script['tag']);

                // ignore script
                if ($filteredSrc === 'ignore') {
                    continue 1;
                }

                // delete script
                if ($filteredSrc === 'delete') {

                    // delete from HTML
                    $this->output->add_search_replace($script['tag'], '');
                    continue 1;
                }

                // replace href
                if ($filteredSrc !== $script['src']) {
                    $script['src'] = $filteredSrc;
                    $script['replaceSrc'] = true;
                }

                // apply script minify filter
                if ($minify && $minify_filter) {
                    $script['minify'] = $this->tools->filter_list_match($script['tag'], $minify_filterType, $minify_filter);
                }

                // apply script async filter
                if ($async && $this->async_filter) {
                    
                    // apply filter
                    $asyncConfig = $this->tools->filter_config_match($script['tag'], $this->async_filter, $this->async_filterType);

                    // filter config object
                    if ($asyncConfig && is_array($asyncConfig)) {

                        // async enabled by filter
                        if (!isset($asyncConfig['async']) || $asyncConfig['async']) {
                            $script['async'] = true;

                            // custom load position
                            if (isset($asyncConfig['load_position']) && $asyncConfig['load_position'] !== $this->load_position) {
                                $script['load_position'] = $asyncConfig['load_position'];
                            }

                            // custom async exec
                            if (isset($asyncConfig['async_exec'])) {
                                $script['async_exec'] = $asyncConfig['async_exec'];
                            }

                            // custom rel_preload
                            if (isset($asyncConfig['rel_preload']) && $asyncConfig['rel_preload'] !== $this->rel_preload) {
                                $script['rel_preload'] = $asyncConfig['rel_preload'];
                            }

                            // custom abide WordPress dependencies
                            if (isset($asyncConfig['abide'])) {
                                $script['abide'] = $asyncConfig['abide'];
                            }

                            // custom localStorage
                            if (isset($asyncConfig['localStorage'])) {
                                $script['localStorage'] = $asyncConfig['localStorage'];
                            }
                        }
                    } elseif ($asyncConfig === true) {

                        // include by default
                        $script['async'] = true;
                    }
                }

                // apply script proxy filter
                if (!$script['minify'] && $proxy && !$this->url->is_local($script['src'], false, false)) {

                    // apply filter
                    $script_proxy = ($proxy_filter) ? $this->tools->filter_list_match($script['tag'], 'include', $proxy_filter) : $proxy;

                    // proxy script
                    if ($script_proxy) {

                        // proxify URL
                        $proxyResult = $this->proxy->proxify('js', $script['src']);

                        // proxy href
                        if ($proxyResult[0] && $proxyResult[1] !== $script['src']) {
                            $script['proxy'] = array($proxyResult[0],$script['src']);
                            $script['src'] = $proxyResult[1];
                            $script['replaceSrc'] = true;
                        }
                    }
                }

                $this->script_elements[] = $script;
            }
        }

        // minify scripts
        if (!empty($this->script_elements) && $minify) {
            $this->minify_scripts();
        }
    }

    /**
     * Minify extracted scripts
     */
    final private function minify_scripts()
    {
        // walk extracted script elements
        foreach ($this->script_elements as $n => $script) {
            
            // skip inline scripts
            if (isset($script['inline']) && $script['inline']) {
                continue;
            }

            // minify disabled
            if (!isset($script['minify']) || !$script['minify']) {
                continue;
            }

            // minify hash
            $urlhash = $this->minify_hash($script['src']);

            // detect local URL
            $local = $this->url->is_local($script['src']);

            $cache_file_hash = $proxy_file_meta = false;

            // local URL, verify change based on content hash
            if ($local) {

                // get local file hash
                $file_hash = md5_file($local);
            } else { // remote URL

                // invalid prefix
                if (!$this->url->valid_protocol($script['src'])) {
                    continue 1;
                }

                // try cache
                if ($this->cache->exists('js', 'src', $urlhash) && (!$this->options->bool('js.minify.clean-js.sourceMap') || $this->cache->exists('js', 'src', $urlhash, false, '.js.map'))) {

                    // verify content
                    $proxy_file_meta = $this->proxy->meta('js', $script['src']);
                    $cache_file_hash = $this->cache->meta('js', 'src', $urlhash, true);

                    if ($proxy_file_meta && $cache_file_hash && $proxy_file_meta[2] === $cache_file_hash) {

                        // preserve cache file based on access
                        $this->cache->preserve('js', 'src', $urlhash, (time() - 3600));
                   
                        // add minified path
                        $this->script_elements[$n]['minified'] = array($urlhash,$cache_file_hash);

                        // update content in background using proxy (conditionl HEAD request)
                        $this->proxy->proxify('js', $script['src']);
                        continue 1;
                    }
                }
                
                // download script using proxy
                try {
                    $scriptData = $this->proxy->proxify('js', $script['src'], 'filedata');
                } catch (HTTPException $err) {
                    $scriptData = false;
                } catch (Exception $err) {
                    $scriptData = false;
                }

                // failed to download file or file is empty
                if (!$scriptData) {
                    continue 1;
                }

                // file hash
                $file_hash = $scriptData[1][2];
                $scriptText = $scriptData[0];
            }

            // get content hash
            $cache_file_hash = ($cache_file_hash) ? $cache_file_hash : $this->cache->meta('js', 'src', $urlhash, true);

            if ($cache_file_hash === $file_hash) {
                
                // preserve cache file based on access
                $this->cache->preserve('js', 'src', $urlhash, (time() - 3600));

                // add minified path
                $this->script_elements[$n]['minified'] = array($urlhash, $file_hash);

                continue 1;
            }
            
            // load script source from local file
            if ($local) {
                $scriptText = trim(file_get_contents($local));
                if ($scriptText === '') {

                    // file is empty, remove
                    $this->output->add_search_replace($script['tag'], '');

                    // store script
                    $this->cache->put(
                        'js',
                        'src',
                        $urlhash,
                        '',
                        false, // suffix
                        false, // gzip
                        false, // opcache
                        $file_hash, // meta
                        true // meta opcache
                    );

                    continue 1;
                }
            }

            // apply script filters before processing
            $scriptText = $this->js_filters($scriptText);

            // target src cache dir
            $target_src_dir = $this->file->directory_url('js/src/' . $this->cache->hash_path($urlhash), 'cache', true);

            // script source
            $sources = array();
            // $this->extract_filename($script['src'])
            //$script['src'] = preg_replace(array('#\?.*$#i'), array(''), $script['src']);
            $script['src'] = $this->extract_filename($script['src']);

            $sources[$script['src']] = array(
                'text' => $scriptText
            );

            try {
                $minified = $this->minify($sources, $target_src_dir);
            } catch (Exception $err) {
                // @todo
                // handle minify failure, prevent overload
                $minified = false;
            } catch (\Exception $err) {
                // @todo
                // handle minify failure, prevent overload
                $minified = false;
            }            // test

            // minified script
            if ($minified) {

                // apply filters
                $minified['text'] = $this->minified_css_filters($minified['text']);

                // footer
                $minified['text'] .= "\n/* @src ".$script['src']." */";

                // store script
                $cache_file_path = $this->cache->put(
                    'js',
                    'src',
                    $urlhash,
                    $minified['text'],
                    false, // suffix
                    false, // gzip
                    false, // opcache
                    $file_hash, // meta
                    true // meta opcache
                );

                // add link to source map
                if (isset($minified['sourcemap'])) {
                    
                    // add link to script
                    $minified['text'] .= "\n/*# sourceMappingURL=".basename($cache_file_path).".map */";

                    // update script cache
                    try {
                        $this->file->put_contents($cache_file_path, $minified['text']);
                    } catch (\Exception $e) {
                        throw new Exception('Failed to store script ' . $this->file->safe_path($cache_file_path) . ' <pre>'.$e->getMessage().'</pre>', 'config');
                    }

                    // apply filters
                    $minified['sourcemap'] = $this->minified_sourcemap_filter($minified['sourcemap']);

                    // store source map
                    try {
                        $this->file->put_contents($cache_file_path . '.map', $minified['sourcemap']);
                    } catch (\Exception $e) {
                        throw new Exception('Failed to store script source map ' . $this->file->safe_path($cache_file_path . '.map') . ' <pre>'.$e->getMessage().'</pre>', 'config');
                    }
                }

                // entry
                $this->script_elements[$n]['minified'] = array($urlhash,$file_hash);
            } else {

                // minification failed
                $this->script_elements[$n]['minified'] = false;
            }
        }
    }

    /**
     * Minify scripts
     */
    final private function minify($sources, $target)
    {
        $this->last_used_minifier = false;

        // load PHP minifier
        if (!class_exists('\JSMin\JSMin')) {
            require_once $this->core->modules('js')->dir_path() . 'lib/JSMin.php';
        }

        // concat sources
        $script = '';
        foreach ($sources as $source) {
            $script .= ' ' . $source['text'];
        }

        // minify
        try {
            $minified = \JSMin\JSMin::minify($script);
        } catch (\Exception $err) {
            throw new Exception('PHP JSMin failed: ' . $err->getMessage(), 'js');
        }
        if (!$minified && $minified !== '') {
            throw new Exception('PHP JSMin failed: unknown error', 'js');
        }

        $this->last_used_minifier = 'php';

        return array('text' => $minified);
    }

    /**
     * Return filename
     * @todo
     */
    final private function extract_filename($src)
    {
        //$basename = basename($src);
        $basename = str_replace('http://abtf.local', '', $src);
        if (strpos($basename, '?') !== false) {
            return explode('?', $basename)[0];
        }

        return $basename;
    }

    /**
     * Extract src from tag
     *
     * @param  string $attributes HTML tag attributes
     * @param  string $replace    src value to replace
     * @return string src or modified tag
     */
    final private function src_regex($attributes, $replace = false)
    {
        // detect if tag has src
        $srcpos = strpos($attributes, 'src');
        if ($srcpos !== false) {

            // regex
            $char = substr($attributes, ($srcpos + 4), 1);
            if ($char === '"' || $char === '\'') {
                $char = preg_quote($char);
                $regex = '#(src\s*=\s*'.$char.')([^'.$char.']+)('.$char.')#Usmi';
            } elseif ($char === ' ' || $char === "\n") {
                $regex = '#(src\s*=\s*["|\'])([^"|\']+)(["|\'])#Usmi';
            } else {
                $attributes .= '>';
                $regex = '#(src\s*=)([^\s>]+)(\s|>)#Usmi';
            }

            // return src
            if (!$replace) {

                // match src
                if (!preg_match($regex, $attributes, $out)) {
                    return false;
                }

                return ($out[2]) ? $this->url->translate_protocol($out[2]) : $out[2];
            }

            // replace src in tag
            $attributes = preg_replace($regex, '$1' . $replace . '$3', $attributes);
        }

        return ($replace) ? $attributes : false;
    }

    /**
     * Apply script CDN or HTTP/@ Server Push to url
     *
     * @param  string $url Stylescript URL
     * @return string href or modified tag
     */
    final private function url_filter($url)
    {
        // setup global CDN
        if (is_null($this->script_cdn)) {

            // global CDN enabled
            if ($this->options->bool('js.cdn')) {

                // global CDN config
                $this->script_cdn = array(
                    $this->options->get('js.cdn.url'),
                    $this->options->get('js.cdn.mask')
                );
            } else {
                $this->script_cdn = false;
            }

            // apply CDN to pushed assets
            $this->http2_push_cdn = $this->options->bool('js.cdn.http2_push');
        }

        // setup global CDN
        if (is_null($this->http2_push)) {

            // global CDN enabled
            if ($this->options->bool('js.http2_push')) {
                if (!$this->options->bool('js.http2_push.filter')) {
                    $this->http2_push = true;
                } else {
                    $filterType = $this->options->get('js.http2_push.filter.type');
                    $filterConfig = ($filterType) ? $this->options->get('js.http2_push.filter.' . $filterType) : false;

                    if (!$filterConfig) {
                        $this->http2_push = false;
                    } else {
                        $this->http2_push = array($filterType, $filterConfig);
                    }
                }
            } else {
                $this->http2_push = false;
            }
        }

        // apply HTTP/2 Server Push
        if ($this->http2_push) {

            // apply script CDN
            $cdn_url = false;
            if ($this->http2_push_cdn) {
                $cdn_url = $this->url->cdn($url, $this->script_cdn);
                if ($cdn_url === $url) {
                    $cdn_url = false;
                } else {
                    $url = $cdn_url;
                }
            }

            if (Core::get('http2')->push($url, 'script', false, $this->http2_push, ($cdn_url ? null : true))) {

                // return original URL that has been pushed
                return $url;
            }

            // return CDN url
            if ($this->http2_push_cdn) {
                return $url;
            }
        }

        // apply script CDN
        return $this->url->cdn($url, $this->script_cdn);
    }

    /**
     * Apply filters to script before processing
     *
     * @param  string $script script to filter
     * @return string Filtered script
     */
    final private function minified_css_filters($script)
    {
        // fix relative URLs
        if (strpos($script, '../') !== false) {
            $script = preg_replace('#\((\../)+wp-(includes|admin|content)/#', '('.$this->url->root_path().'wp-$2/', $script);
        }

        return $script;
    }

    /**
     * Apply filters to minified sourcemap
     *
     * @param  string $json Sourcemap JSON
     * @return string Filtered sourcemap JSON
     */
    final private function minified_sourcemap_filter($json)
    {

        // fix relative paths
        if (strpos($json, '../') !== false || strpos($json, '"wp-') !== false) {
            $json = preg_replace('#"(\../)*wp-(includes|admin|content)/#s', '"'.$this->url->root_path().'wp-$2/', $json);
        }

        return $json;
    }

    /**
     * Return resource minification hash
     *
     * To enable different minification settings per page, any settings that modify the script before minification should be used in the hash.
     *
     * @param  string $resource Resource
     * @return string MD5 hash for resource
     */
    final public function minify_hash($resource)
    {
        
        // return default hash
        return md5($resource);
    }
    
    /**
     * Sanitize group filter
     */
    final public function sanitize_filter($concat_filter)
    {
        if (!is_array($concat_filter) || empty($concat_filter)) {
            $concat_filter = false;
        }

        // sanitize groups by key reference
        $sanitized_groups = array();
        foreach ($concat_filter as $filter) {
            if (!isset($filter['match']) || empty($filter['match'])) {
                continue;
            }

            if (isset($filter['group']) && isset($filter['group']['key'])) {
                $sanitized_groups[$filter['group']['key']] = $filter;
            } else {
                $sanitized_groups[] = $filter;
            }
        }

        return $sanitized_groups;
    }

    /**
     * Apply filter
     */
    final public function apply_filter(&$concat_group, &$concat_group_settings, $tag, $concat_filter)
    {
        if (!is_array($concat_filter)) {
            throw new Exception('Concat group filter not array.', 'core');
        }

        $filter_set = false; // group set flag
        
        // match group filter list
        foreach ($concat_filter as $key => $filter) {

            // verify filter config
            if (!is_array($filter) || empty($filter) || (!isset($filter['match']) && !isset($filter['match_regex']))) {
                continue 1;
            }

            // exclude rule
            $exclude_filter = (isset($filter['exclude']) && $filter['exclude']);

            // string based match
            if (isset($filter['match']) && !empty($filter['match'])) {
                foreach ($filter['match'] as $match_string) {
                    $exclude = false;
                    $regex = false;

                    // filter config
                    if (is_array($match_string)) {
                        $exclude = (isset($match_string['exclude'])) ? $match_string['exclude'] : false;
                        $regex = (isset($match_string['regex'])) ? $match_string['regex'] : false;
                        $match_string = $match_string['string'];
                    }

                    // group set, just apply exclude filters
                    if ($filter_set && !$exclude && !$exclude_filter) {
                        continue 1;
                    }

                    if ($regex) {
                        $match = false;
                        try {
                            if (@preg_match($match_string, $tag)) {

                                // exclude filter
                                if ($exclude || $exclude_filter) {
                                    $concat_group = false;

                                    return;
                                }

                                $match = true;
                            }
                        } catch (\Exception $err) {
                            $match = false;
                        }

                        if ($match) {

                            // match, assign to group
                            $concat_group = md5(json_encode($filter));
                            if (!isset($concat_group_settings[$concat_group])) {
                                $concat_group_settings[$concat_group] = array();
                            }
                            $concat_group_settings[$concat_group] = array_merge($filter, $concat_group_settings[$concat_group]);
                            
                            $filter_set = true;
                        }
                    } else {
                        if (strpos($tag, $match_string) !== false) {

                            // exclude filter
                            if ($exclude || $exclude_filter) {
                                $concat_group = false;

                                return;
                            }

                            // match, assign to group
                            $concat_group = md5(json_encode($filter));
                            if (!isset($concat_group_settings[$concat_group])) {
                                $concat_group_settings[$concat_group] = array();
                            }
                            $concat_group_settings[$concat_group] = array_merge($filter, $concat_group_settings[$concat_group]);

                            $filter_set = true;
                        }
                    }
                }
            }
        }
    }

    /**
     * Return concat hash path for async list
     *
     * @param  string $hash Hash key for concat stylesheet
     * @return string Hash path for async list.
     */
    final public function async_hash_path($hash)
    {
        // get index id
        $index_id = $this->cache->index_id('js', 'concat', $hash);

        if (!$index_id) {
            throw new Exception('Failed to retrieve concat hash index ID.', 'text');
        }
        if (is_array($index_id)) {
            $suffix = $index_id[1];
            $index_id = $index_id[0];
        } else {
            $suffix = false;
        }

        // return hash path
        return str_replace('/', '|', $this->cache->index_path($index_id)) . $index_id . (($suffix) ? ':' . $suffix : '');
    }

    /**
     * Return timing config
     *
     * @param   array   Timing config
     * @return array Client compressed timing config
     */
    final private function timing_config($config)
    {
        if (!$config || !is_array($config) || !isset($config['type'])) {
            return false;
        }


        // init config with type index
        $timing_config = array($this->client->config_index('key', $config['type']));

        // timing config
        switch (strtolower($config['type'])) {
            case "requestanimationframe":
                
                // frame
                $frame = (isset($config['frame']) && is_numeric($config['frame'])) ? $config['frame'] : 1;
                if ($frame > 1) {
                    $timing_config[1] = array();
                    $timing_config[1][$this->client->config_index('key', 'frame')] = $frame;
                }
            break;
            case "requestidlecallback":
                
                // timeout
                $timeout = (isset($config['timeout']) && is_numeric($config['timeout'])) ? $config['timeout'] : '';
                if ($timeout) {
                    $timing_config[1] = array();
                    $timing_config[1][$this->client->config_index('key', 'timeout')] = $timeout;
                }

                // setTimeout fallback
                $setTimeout = (isset($config['setTimeout']) && is_numeric($config['setTimeout'])) ? $config['setTimeout'] : '';
                if ($setTimeout) {
                    if (!isset($timing_config[1])) {
                        $timing_config[1] = array();
                    }
                    $timing_config[1][$this->client->config_index('key', 'setTimeout')] = $setTimeout;
                }
            break;
            case "inview":

                // selector
                $selector = (isset($config['selector'])) ? trim($config['selector']) : '';
                if ($selector !== '') {
                    $timing_config[1] = array();
                    $timing_config[1][$this->client->config_index('key', 'selector')] = $selector;
                }

                // offset
                $offset = (isset($config['offset']) && is_numeric($config['offset'])) ? $config['offset'] : 0;
                if ($offset > 0) {
                    if (!isset($timing_config[1])) {
                        $timing_config[1] = array();
                    }
                    $timing_config[1][$this->client->config_index('key', 'offset')] = $offset;
                }
            break;
            case "media":

                // media query
                $media = (isset($config['media'])) ? trim($config['media']) : '';
                if ($media !== '') {
                    $timing_config[1] = array();
                    $timing_config[1][$this->client->config_index('key', 'media')] = $media;
                }
            break;
        }

        return $timing_config;
    }
}