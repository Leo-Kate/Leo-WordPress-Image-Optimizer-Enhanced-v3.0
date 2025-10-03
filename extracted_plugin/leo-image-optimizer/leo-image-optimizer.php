<?php
/**
 * Plugin Name: WordPress Image Optimizer Enhanced
 * Description: Automatically converts uploaded images to WebP (or AVIF) and provides a batch conversion tool for existing images. Version 3.2 improves large‑site performance by removing expensive ID exclusion queries, honours your chosen target format and resizing options during batch conversion, and adds a robust stop mechanism to gracefully halt ongoing operations. All previous features like lazy loading and CDN support are retained.
 * Version: 3.2
 * Author: Leo
 * Author URI: #
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: manus-image-optimizer
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define( 'MANUS_IMAGE_OPTIMIZER_VERSION', '3.0' );
define( 'MANUS_IMAGE_OPTIMIZER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MANUS_IMAGE_OPTIMIZER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main plugin class
 */
class ManusImageOptimizer {
    
    private $default_quality = 80;
    private $batch_delay = 2; // 2 seconds delay between batches for cron
    private $batch_size = 5; // Reduced batch size for better server protection

    /**
     * The plugin historically tracked the ID of the last processed attachment in an option
     * and used that to exclude all previous IDs from subsequent queries.  While this
     * technique works for small sites, it becomes increasingly inefficient on larger
     * installations because WordPress must build a large `post__not_in` array.  In
     * version 3.1 we stop relying on `last_processed_id` to paginate our batches
     * and instead always query for attachments that have not yet been converted
     * (i.e. `_manus_webp_converted` meta is missing or equal to `0`).  This
     * eliminates the need for large exclusion lists and allows the database to
     * leverage indexes on the `postmeta` table.  The option remains defined for
     * backwards compatibility but is no longer used.
     */
    
    private function log_error( $message, $image_path = null ) {
        $error_log = get_option( 'manus_image_optimizer_error_log', array() );
        $error_entry = array(
            'timestamp' => current_time( 'mysql' ),
            'message'   => $message,
            'image_path' => $image_path,
        );
        $error_log[] = $error_entry;
        // Keep log size manageable, e.g., last 100 errors
        if ( count( $error_log ) > 100 ) {
            array_shift( $error_log );
        }
        update_option( 'manus_image_optimizer_error_log', $error_log );
        error_log( 'Manus Image Optimizer Error: ' . $message . ( $image_path ? ' (Image: ' . $image_path . ')' : '' ) );
    }
    
    public function __construct() {
        add_action( 'init', array( $this, 'init' ) );
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
    }
    
    public function init() {
        // Admin menu and AJAX handlers (always available for settings access)
        if ( is_admin() ) {
            add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
            add_action( 'wp_ajax_manus_get_eligible_images_count', array( $this, 'ajax_get_eligible_images_count' ) );
            add_action( 'wp_ajax_manus_get_plugin_settings', array( $this, 'ajax_get_plugin_settings' ) );
            add_action( 'wp_ajax_manus_update_plugin_settings', array( $this, 'ajax_update_plugin_settings' ) );
            add_action( 'wp_ajax_manus_start_conversion', array( $this, 'ajax_start_conversion' ) );
            add_action( 'wp_ajax_manus_stop_conversion', array( $this, 'ajax_stop_conversion' ) );
            add_action( 'wp_ajax_manus_get_conversion_progress', array( $this, 'ajax_get_conversion_progress' ) );
            add_action( 'wp_ajax_manus_check_image_libs', array( $this, 'ajax_check_image_libs' ) );
            // Additional AJAX endpoints for cleaning up originals and testing MIME
            add_action( 'wp_ajax_manus_cleanup_original_images', array( $this, 'ajax_cleanup_original_images' ) );
            add_action( 'wp_ajax_manus_test_image_mime', array( $this, 'ajax_test_image_mime' ) );
            // The plugin previously exposed custom activate/deactivate AJAX handlers
            // which were used to toggle an internal "active" flag separate from
            // WordPress' own plugin activation mechanism.  This behaviour has
            // been removed so the plugin is considered active whenever it is
            // installed and activated via the Plugins screen.  Therefore we
            // intentionally do not register any additional activation/deactivation
            // handlers here.
        }

        // Always initialise functional hooks when the plugin is active in
        // WordPress.  The plugin used to require a separate enable/disable
        // toggle via its own settings page.  That behaviour has been removed
        // so this code is always executed.

        // Add WebP MIME type support
        add_filter( 'upload_mimes', array( $this, 'add_image_mime_types' ) );
        
        // Auto-convert uploaded images
        add_filter( 'wp_handle_upload', array( $this, 'auto_convert_uploaded_image' ) );
        
        // Lazy loading and CDN support
        add_filter( 'the_content', array( $this, 'add_lazy_loading_to_content' ), 20 );
        add_filter( 'post_thumbnail_html', array( $this, 'add_lazy_loading_to_thumbnail' ), 20 );
        add_filter( 'wp_get_attachment_image', array( $this, 'add_lazy_loading_to_attachment_image' ), 20 );
        add_filter( 'wp_get_attachment_url', array( $this, 'apply_cdn_to_attachment_url' ), 20 );
        add_filter( 'wp_get_attachment_image_src', array( $this, 'apply_cdn_to_attachment_image_src' ), 20 );

        // Frontend scripts for lazy loading
        if ( ! is_admin() ) {
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );
        }

        /*
         * Ensure that the attachment MIME type in the posts table reflects the
         * actual file format on disk.  Without this hook WordPress retains
         * the original `post_mime_type` (e.g. image/jpeg) even after the
         * image has been converted to WebP or AVIF.  This causes confusion
         * because the file extension is changed to .webp/.avif but the media
         * library still reports the old MIME type.  By filtering
         * `wp_insert_attachment_data` we can inspect the file extension and
         * override the MIME type appropriately before the attachment is saved.
         */
        add_filter( 'wp_insert_attachment_data', array( $this, 'filter_attachment_mime_type' ), 10, 2 );

        // WP-Cron hook
        add_action( 'manus_image_optimizer_batch_cron', array( $this, 'process_image_batch_cron' ) );
    }
    
    public function activate() {
        // Create options with default values
        add_option( 'manus_image_optimizer_quality', $this->default_quality );
        // Enable auto conversion by default.  Previous versions disabled it
        // by default and exposed a settings checkbox.  We now always convert
        // newly uploaded images so default to enabled.
        // Enable auto conversion by default on first activation.  Users can
        // subsequently disable this option via the settings page.
        add_option( 'manus_image_optimizer_auto_convert', 1 );
        add_option( 'manus_image_optimizer_batch_size', $this->batch_size );
        // Do not delete originals by default.  Retaining the original file
        // allows for later review and manual clean‑up.  Users can enable
        // automatic deletion via the settings page.
        add_option( 'manus_image_optimizer_delete_original', 0 );
        add_option( 'manus_image_optimizer_processed_count', 0 );
        add_option( 'manus_image_optimizer_error_count', 0 );
        add_option( 'manus_image_optimizer_is_processing', 0 );
        add_option( 'manus_image_optimizer_last_processed_id', 0 ); // Track last processed ID
        add_option( 'manus_image_optimizer_processor', 'gd' ); // Default to GD
        
        // New options for lazy loading and CDN
        add_option( 'manus_image_optimizer_lazy_loading', 1 ); // Enable lazy loading by default
        add_option( 'manus_image_optimizer_cdn_enabled', 0 ); // Disable CDN by default
        add_option( 'manus_image_optimizer_cdn_url', '' ); // CDN URL
        add_option( 'manus_image_optimizer_lazy_loading_threshold', '200px' ); // Lazy loading threshold
        add_option( 'manus_image_optimizer_target_format', 'webp' ); // New option for target format
        add_option( 'manus_image_optimizer_max_width', 0 ); // New option for max width
        add_option( 'manus_image_optimizer_max_height', 0 ); // New option for max height
        add_option( 'manus_image_optimizer_error_log', array() ); // New option for error log

        // The plugin used to set an internal activation flag.  This is no
        // longer necessary because WordPress controls plugin activation.  The
        // plugin will always behave as active when installed and activated.

        // Flag used to gracefully stop a running batch.  When set to 1 the next
        // cron invocation will abort and clean up without scheduling further events.
        add_option( 'manus_image_optimizer_stop_requested', 0 );

        // Log activation
        error_log( 'Manus Image Optimizer plugin activated' );

        // Attempt to add WebP/AVIF MIME configuration directives to the
        // site's .htaccess file if running under Apache.  This method is
        // idempotent and will only append the required AddType lines once.
        $this->maybe_add_webp_htaccess();
    }
    
    public function deactivate() {
        // Clear all plugin options on deactivation
        delete_option( 'manus_image_optimizer_quality' );
        delete_option( 'manus_image_optimizer_auto_convert' );
        delete_option( 'manus_image_optimizer_batch_size' );
        delete_option( 'manus_image_optimizer_delete_original' );
        delete_option( 'manus_image_optimizer_processed_count' );
        delete_option( 'manus_image_optimizer_error_count' );
        delete_option( 'manus_image_optimizer_is_processing' );
        delete_option( 'manus_image_optimizer_last_processed_id' );
        delete_option( 'manus_image_optimizer_processor' );
        delete_option( 'manus_image_optimizer_lazy_loading' );
        delete_option( 'manus_image_optimizer_cdn_enabled' );
        delete_option( 'manus_image_optimizer_cdn_url' );
        delete_option( 'manus_image_optimizer_lazy_loading_threshold' );
        delete_option( 'manus_image_optimizer_target_format' );
        delete_option( 'manus_image_optimizer_max_width' );
        delete_option( 'manus_image_optimizer_max_height' );
        delete_option( 'manus_image_optimizer_error_log' );
        // Do not delete a non‑existent internal activation flag.  The plugin no
        // longer relies on a custom active option.

        // Remove stop flag on deactivation
        delete_option( 'manus_image_optimizer_stop_requested' );

        // Clear scheduled cron event
        wp_clear_scheduled_hook( 'manus_image_optimizer_batch_cron' );
        
        // Log deactivation
        error_log( 'Manus Image Optimizer plugin deactivated' );
    }
    
    /**
     * Add WebP MIME type support
     */
    public function add_image_mime_types( $mimes ) {
        $mimes["webp"] = "image/webp";
        $mimes["avif"] = "image/avif";
        return $mimes;
    }
    
    /**
     * Auto-convert uploaded images to WebP/AVIF.
     *
     * Prior versions of this plugin exposed a checkbox in the settings
     * screen that allowed users to enable or disable automatic
     * conversion of newly uploaded images.  However the AJAX logic
     * responsible for saving that setting proved unreliable on some
     * installations, causing unexpected failures where new uploads
     * were not converted at all.  To provide a more predictable
     * experience we now always convert eligible uploads.  Users who
     * wish to opt out can simply deactivate the plugin.
     *
     * @param array $file Array of data for the uploaded file.
     * @return array Modified file array with updated path, URL and MIME type.
     */
    public function auto_convert_uploaded_image( $file ) {
        // Only auto convert if the user has enabled this option.  The
        // manus_image_optimizer_auto_convert option defaults to 1 on activation
        // but can be toggled on the settings page.  If disabled, return
        // immediately without modification.
        $auto_convert_enabled = get_option( 'manus_image_optimizer_auto_convert', 0 );
        if ( $auto_convert_enabled != 1 ) {
            return $file;
        }

        // Process only images that are not already WebP/AVIF.  Ignore non‑image uploads.
        if ( isset( $file['type'] ) && strpos( $file['type'], 'image/' ) !== false && $file['type'] !== 'image/webp' && $file['type'] !== 'image/avif' ) {
            $quality         = get_option( 'manus_image_optimizer_quality',        $this->default_quality );
            $delete_original = get_option( 'manus_image_optimizer_delete_original', 1 );
            $processor       = get_option( 'manus_image_optimizer_processor',      'gd' );
            $target_format   = get_option( 'manus_image_optimizer_target_format',  'webp' );
            $max_width       = get_option( 'manus_image_optimizer_max_width',      0 );
            $max_height      = get_option( 'manus_image_optimizer_max_height',     0 );

            $converted_info = $this->convert_image_format( $file['file'], $quality, $delete_original, $processor, $target_format, $max_width, $max_height );
            if ( $converted_info ) {
                // Update file info to reflect the new file
                $file['file'] = $converted_info['path'];
                $file['url']  = str_replace( basename( $file['url'] ), basename( $converted_info['path'] ), $file['url'] );
                $file['type'] = $converted_info['mime_type'];
                error_log( 'Manus Image Optimizer: Auto converted uploaded image to ' . strtoupper( $target_format ) . ': ' . basename( $converted_info['path'] ) );
            }
        }
        return $file;
    }
    
    /**
     * Convert image to WebP format
     */
    public function convert_image_format( $source_path, $quality = 80, $delete_original = true, $processor = 'gd', $target_format = 'webp', $max_width = 0, $max_height = 0 ) {
        // Validate input
        if ( ! file_exists( $source_path ) ) {
            $this->log_error( 'Source file does not exist: ' . $source_path, $source_path );
            return false;
        }
        
        $file_info = pathinfo( $source_path );
        $extension = strtolower( $file_info['extension'] );
        $destination_path = $file_info['dirname'] . '/' . $file_info['filename'] . '.' . $target_format;
        $mime_type = 'image/' . $target_format;
        
        // Check if target format version already exists
        if ( file_exists( $destination_path ) ) {
            // Compare file sizes to ensure conversion was successful
            $original_size = filesize( $source_path );
            $target_size = filesize( $destination_path );
            
            if ( $target_size > 0 && $target_size < $original_size ) {
                // Target file exists and is smaller, assume it's good
                if ( $delete_original && $source_path !== $destination_path ) {
                    unlink( $source_path );
                }
                return array( 'path' => $destination_path, 'mime_type' => $mime_type );
            }
        }
        
        try {
            $image = false;
            $original_width = 0;
            $original_height = 0;

            // Create image resource based on file type
            switch ( $extension ) {
                case 'jpeg':
                case 'jpg':
                    $image = imagecreatefromjpeg( $source_path );
                    break;
                case 'png':
                    $image = imagecreatefrompng( $source_path );
                    // Preserve transparency for PNGs
                    if ( $image ) {
                        imagealphablending( $image, false );
                        imagesavealpha( $image, true );
                    }
                    break;
                case 'gif':
                    $image = imagecreatefromgif( $source_path );
                    break;
                default:
                    $this->log_error( 'Unsupported image format: ' . $extension, $source_path );
                    return false;
            }
            
            if ( ! $image ) {
                $this->log_error( 'Failed to create image resource from: ' . $source_path, $source_path );
                return false;
            }

            $original_width = imagesx( $image );
            $original_height = imagesy( $image );

            // Image resizing logic
            if ( ( $max_width > 0 && $original_width > $max_width ) || ( $max_height > 0 && $original_height > $max_height ) ) {
                $ratio = min( ($max_width > 0 ? $max_width / $original_width : 1), ($max_height > 0 ? $max_height / $original_height : 1) );
                $new_width = (int)($original_width * $ratio);
                $new_height = (int)($original_height * $ratio);

                $resized_image = imagecreatetruecolor( $new_width, $new_height );
                if ( $extension === 'png' ) {
                    imagealphablending( $resized_image, false );
                    imagesavealpha( $resized_image, true );
                }
                imagecopyresampled( $resized_image, $image, 0, 0, 0, 0, $new_width, $new_height, $original_width, $original_height );
                imagedestroy( $image );
                $image = $resized_image;
            }

            // Convert to true color if not already.  Some PHP builds (e.g. old GD
            // versions) do not expose imagepalettetotruecolor(), so guard the call.
            if ( ! imageistruecolor( $image ) && function_exists( 'imagepalettetotruecolor' ) ) {
                @imagepalettetotruecolor( $image );
            }

            if ( $processor === 'imagick' && class_exists( 'Imagick' ) ) {
                $imagick = new Imagick();
                $imagick->readImage( $source_path );
                
                // Apply resizing if Imagick is used
                if ( ( $max_width > 0 && $original_width > $max_width ) || ( $max_height > 0 && $original_height > $max_height ) ) {
                    $imagick->resizeImage( $new_width, $new_height, Imagick::FILTER_LANCZOS, 1 );
                }

                $imagick->setImageFormat( $target_format );
                $imagick->setOption( $target_format . ':lossless', 'false' );
                $imagick->setImageCompressionQuality( $quality );
                $imagick->stripImage(); // Remove metadata for smaller file size
                $imagick->writeImage( $destination_path );
                $imagick->destroy();
            } else { // Fallback to GD
                // Check if GD library supports target format
                if ( $target_format === 'webp' && ! function_exists( 'imagewebp' ) ) {
                    $this->log_error( 'GD library does not support WebP conversion. Please ensure your GD library is compiled with WebP support.', $source_path );
                    return false;
                } elseif ( $target_format === 'avif' && ! function_exists( 'imageavif' ) ) {
                    $this->log_error( 'GD library does not support AVIF conversion. Please ensure your GD library is compiled with AVIF support.', $source_path );
                    return false;
                }
                
                // Strip EXIF data for smaller file size (GD doesn't preserve it anyway)
                $result = false;
                if ( $target_format === 'webp' ) {
                    $result = imagewebp( $image, $destination_path, $quality );
                } elseif ( $target_format === 'avif' ) {
                    // AVIF quality range is different (0-100 where higher is better)
                    $result = imageavif( $image, $destination_path, $quality );
                }
                imagedestroy( $image );
            }
            
            if ( file_exists( $destination_path ) ) {
                // Log successful conversion
                $original_size = filesize( $source_path );
                $new_size = filesize( $destination_path );
                $compression_ratio = round( ( 1 - $new_size / $original_size ) * 100, 1 );
                
                error_log( sprintf( 
                    'Manus Image Optimizer: Converted %s to %s (%.1f%% compression, %d -> %d bytes) using %s',
                    basename( $source_path ),
                    strtoupper( $target_format ),
                    $compression_ratio,
                    $original_size,
                    $new_size,
                    $processor
                ) );
                
                // Delete original file if requested
                if ( $delete_original && $source_path !== $destination_path ) {
                    unlink( $source_path );
                }
                
                return array( 'path' => $destination_path, 'mime_type' => $mime_type );
            } else {
                $this->log_error( sprintf( 'Failed to save %s image %s. Check server logs for more details.', strtoupper( $target_format ), basename( $destination_path ) ), $source_path );
                return false;
            }
            
        } catch ( Exception $e ) {
            $this->log_error( 'Exception during conversion: ' . $e->getMessage(), $source_path );
            return false;
        }
    }
    
    /**
     * Add lazy loading to content images
     */
    public function add_lazy_loading_to_content( $content ) {
        if ( ! get_option( 'manus_image_optimizer_lazy_loading', 1 ) || is_admin() || is_feed() ) {
            return $content;
        }
        
        // Use preg_replace_callback to add lazy loading attributes
        $content = preg_replace_callback(
            '/<img([^>]+?)src=[\'"]?([^\'"\s>]+)[\'"]?([^>]*)>/i',
            array( $this, 'add_lazy_loading_attributes' ),
            $content
        );
        
        return $content;
    }
    
    /**
     * Add lazy loading to post thumbnails
     */
    public function add_lazy_loading_to_thumbnail( $html ) {
        if ( ! get_option( 'manus_image_optimizer_lazy_loading', 1 ) || is_admin() || is_feed() ) {
            return $html;
        }
        
        return preg_replace_callback(
            '/<img([^>]+?)src=[\'"]?([^\'"\s>]+)[\'"]?([^>]*)>/i',
            array( $this, 'add_lazy_loading_attributes' ),
            $html
        );
    }
    
    /**
     * Add lazy loading to attachment images
     */
    public function add_lazy_loading_to_attachment_image( $html ) {
        if ( ! get_option( 'manus_image_optimizer_lazy_loading', 1 ) || is_admin() || is_feed() ) {
            return $html;
        }
        
        return preg_replace_callback(
            '/<img([^>]+?)src=[\'"]?([^\'"\s>]+)[\'"]?([^>]*)>/i',
            array( $this, 'add_lazy_loading_attributes' ),
            $html
        );
    }
    
    /**
     * Callback function to add lazy loading attributes
     */
    public function add_lazy_loading_attributes( $matches ) {
        $before_src = $matches[1];
        $src = $matches[2];
        $after_src = $matches[3];
        
        // Skip if already has loading attribute or data-src
        if ( strpos( $before_src . $after_src, 'loading=' ) !== false || 
             strpos( $before_src . $after_src, 'data-src=' ) !== false ) {
            return $matches[0];
        }
        
        // Apply CDN if enabled
        $src = $this->apply_cdn_to_url( $src );
        
        // Add lazy loading attributes
        $lazy_attributes = ' loading="lazy" data-src="' . esc_attr( $src ) . '"';
        
        // Create a placeholder (1x1 transparent pixel)
        $placeholder = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"%3E%3C/svg%3E';
        
        return '<img' . $before_src . 'src="' . $placeholder . '"' . $lazy_attributes . $after_src . '>';
    }

    /**
     * Filter attachment data just before it is inserted into the database to
     * ensure the `post_mime_type` value matches the file's extension.  When
     * our plugin converts an uploaded image to WebP or AVIF the file path
     * returned to WordPress already has its extension changed.  However
     * WordPress uses the originally detected MIME type (e.g. image/jpeg) to
     * populate the attachment post.  This filter inspects the file path,
     * determines the correct MIME type based on the extension and updates
     * `post_mime_type` accordingly.  The `$data` array contains a
     * `file` element when called from `wp_insert_attachment()` but not
     * when updating an existing attachment, so we also fall back to
     * `get_attached_file()` when possible.
     *
     * @param array $data    Sanitised attachment data
     * @param array $postarr Raw attachment data
     * @return array Modified attachment data
     */
    public function filter_attachment_mime_type( $data, $postarr ) {
        $file_path = '';
        // Prefer the file path provided by wp_insert_attachment if available
        if ( isset( $data['file'] ) && ! empty( $data['file'] ) ) {
            $file_path = $data['file'];
        } elseif ( isset( $data['ID'] ) && (int)$data['ID'] > 0 ) {
            // Fallback: get the attached file for existing attachments
            $maybe_path = get_attached_file( $data['ID'] );
            if ( $maybe_path ) {
                $file_path = $maybe_path;
            }
        }
        if ( $file_path ) {
            $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
            if ( $extension === 'webp' ) {
                $data['post_mime_type'] = 'image/webp';
            } elseif ( $extension === 'avif' ) {
                $data['post_mime_type'] = 'image/avif';
            }
        }
        return $data;
    }
    
    /**
     * Apply CDN to attachment URLs
     */
    public function apply_cdn_to_attachment_url( $url ) {
        return $this->apply_cdn_to_url( $url );
    }
    
    /**
     * Apply CDN to attachment image src
     */
    public function apply_cdn_to_attachment_image_src( $image ) {
        if ( is_array( $image ) && isset( $image[0] ) ) {
            $image[0] = $this->apply_cdn_to_url( $image[0] );
        }
        return $image;
    }
    
    /**
     * Apply CDN URL to image URLs
     */
    private function apply_cdn_to_url( $url ) {
        if ( ! get_option( 'manus_image_optimizer_cdn_enabled', 0 ) ) {
            return $url;
        }
        
        $cdn_url = get_option( 'manus_image_optimizer_cdn_url', '' );
        if ( empty( $cdn_url ) ) {
            return $url;
        }
        
        // Ensure CDN URL uses HTTPS if site URL is HTTPS
        if ( is_ssl() ) {
            $cdn_url = str_replace( 'http://', 'https://', $cdn_url );
        }

        // Get WordPress upload directory info
        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'];

        // Only apply CDN to local images within the uploads directory
        if ( strpos( $url, $base_url ) === 0 ) {
            // Replace the base URL with the CDN URL
            $url = str_replace( $base_url, rtrim( $cdn_url, '/' ), $url );
        }
        
        return $url;
    }
    
    /**
     * Enqueue frontend scripts for lazy loading (optimized)
     */
    public function enqueue_frontend_scripts() {
        if ( ! get_option( 'manus_image_optimizer_lazy_loading', 1 ) ) {
            return;
        }
        
        // Enqueue minified lazy loading script without jQuery dependency
        wp_enqueue_script(
            'manus-lazy-load',
            MANUS_IMAGE_OPTIMIZER_PLUGIN_URL . 'js/lazy-load.js',
            array(),
            MANUS_IMAGE_OPTIMIZER_VERSION,
            array( 'strategy' => 'defer', 'in_footer' => true )
        );
        
        // Pass threshold to script
        $threshold = get_option( 'manus_image_optimizer_lazy_loading_threshold', '200px' );
        wp_localize_script( 'manus-lazy-load', 'manusLazyConfig', array(
            'threshold' => $threshold
        ) );
        
        // Enqueue styles with version for cache busting
        wp_enqueue_style( 
            'manus-image-optimizer-style', 
            MANUS_IMAGE_OPTIMIZER_PLUGIN_URL . 'css/manus-image-optimizer.css', 
            array(), 
            MANUS_IMAGE_OPTIMIZER_VERSION 
        );
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_media_page(
            'Image Optimizer',
            'Image Optimizer',
            'manage_options',
            'manus-image-optimizer',
            array( $this, 'admin_page' )
        );
    }
    
    /**
     * Enqueue admin scripts (optimized)
     */
    public function enqueue_admin_scripts( $hook ) {
        if ( $hook !== 'media_page_manus-image-optimizer' ) {
            return;
        }
        
        // Enqueue minified admin script
        wp_enqueue_script(
            'manus-admin-script',
            MANUS_IMAGE_OPTIMIZER_PLUGIN_URL . 'js/admin.js',
            array(),
            MANUS_IMAGE_OPTIMIZER_VERSION,
            array( 'in_footer' => true )
        );
        
        wp_localize_script( 'manus-admin-script', 'manusImageOptimizer', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'manus_image_optimizer_nonce' )
        ) );
        
        // Enqueue admin styles with version for cache busting
        wp_enqueue_style(
            'manus-admin-style',
            MANUS_IMAGE_OPTIMIZER_PLUGIN_URL . 'css/admin.css',
            array(),
            MANUS_IMAGE_OPTIMIZER_VERSION
        );
    }
    
    /**
     * Admin page content
     */
    public function admin_page() {
        // Historically the plugin stored its own activation flag and showed
        // different UI elements depending on whether the plugin was enabled
        // within its settings page.  This behaviour has been removed.  The
        // plugin is active whenever it is installed and activated via the
        // WordPress Plugins screen, so we no longer query a custom option.
        ?>
        <div id="manus-image-optimizer-wrap" class="wrap">
            <h1>WordPress Image Optimizer Enhanced v3.0</h1>
            
            <!-- Always show the plugin as active.  The previous activate/deactivate
                 buttons have been removed because the plugin now relies solely
                 on WordPress' standard activation mechanism. -->
            <div class="notice notice-success">
                <p><strong>插件状态：</strong>插件已激活并正常运行。</p>
            </div>
            
            <div class="notice notice-warning">
                <p><strong>重要提醒：</strong>在使用此工具之前，请务必备份您的网站和数据库！图片转换过程会删除原始文件以节省空间。</p>
            </div>

            <?php
            // Display server configuration guidance for WebP/AVIF MIME types
            $server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( $_SERVER['SERVER_SOFTWARE'] ) : '';
            if ( strpos( $server_software, 'apache' ) !== false ) {
                echo '<div class="notice notice-info"><p><strong>服务器环境检测：</strong> 检测到您正在使用 Apache。插件已尝试自动向站点的 .htaccess 文件写入 <code>AddType image/webp .webp</code> 和 <code>AddType image/avif .avif</code> 指令，以确保服务器正确返回 WebP/AVIF 的 MIME 类型。若仍存在 MIME 错误，请手动在站点根目录的 .htaccess 中加入以下代码：<br/><code>AddType image/webp .webp<br/>AddType image/avif .avif</code></p></div>';
            } elseif ( strpos( $server_software, 'nginx' ) !== false ) {
                echo '<div class="notice notice-info"><p><strong>服务器环境检测：</strong> 检测到您正在使用 Nginx。请在您的 Nginx 配置的 <code>http{}</code> 或 <code>server{}</code> 块中添加以下内容以支持 WebP/AVIF：<br/><code>types {\n    image/webp webp;\n    image/avif avif;\n}</code><br/>保存配置并重载 Nginx 后生效。</p></div>';
            }
            ?>
            
            <!-- Settings Section with responsive two‑column layout -->
            <!-- Use a custom card container instead of the default WordPress card so we can
                 control the width.  The default .card element has a fixed max‑width
                 which leaves a large empty area on the right side.  Our custom
                 .manus-card class fills the available horizontal space. -->
            <div class="manus-card">
                <h2>插件设置</h2>
                <div class="manus-settings-grid">
                    <!-- Column 1: general settings -->
                    <div class="manus-settings-column">
                        <table class="form-table">
                            <tr>
                                <th scope="row">WebP 质量</th>
                                <td>
                                    <input type="number" id="manus-quality" min="1" max="100" value="80" />
                                    <p class="description">WebP图片质量 (1-100)，推荐值：70-90</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">批处理大小</th>
                                <td>
                                    <input type="number" id="manus-batch-size" min="1" max="20" value="5" />
                                    <p class="description">每批处理的图片数量，较小的值更安全</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">图片处理器</th>
                                <td>
                                    <select id="manus-processor">
                                        <option value="gd">GD Library</option>
                                        <option value="imagick">ImageMagick</option>
                                    </select>
                                    <p class="description">选择用于图片处理的库。ImageMagick通常性能更优，但需要服务器支持。</p>
                                    <p id="manus-processor-status" class="description"></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">自动转换新上传图片</th>
                                <td>
                                    <input type="checkbox" id="manus-auto-convert" />
                                    <p class="description">勾选后，所有新上传的图片都会自动转换为 WebP/AVIF。</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <!-- Column 2: lazy loading & CDN settings -->
                    <div class="manus-settings-column">
                        <h3>懒加载设置</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">启用图片懒加载</th>
                                <td>
                                    <input type="checkbox" id="manus-lazy-loading" checked />
                                    <p class="description">启用后，图片将在用户滚动到可视区域时才加载，提升页面加载速度</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">懒加载触发距离</th>
                                <td>
                                    <input type="text" id="manus-lazy-threshold" value="200px" />
                                    <p class="description">图片距离可视区域多远时开始加载（如：200px, 50%）</p>
                                </td>
                            </tr>
                        </table>
                        <h3>CDN设置</h3>
                        <table class="form-table">
                            <tr>
                                <th scope="row">启用CDN</th>
                                <td>
                                    <input type="checkbox" id="manus-cdn-enabled" />
                                    <p class="description">启用后，图片将通过CDN加速分发</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">CDN URL</th>
                                <td>
                                    <input type="url" id="manus-cdn-url" placeholder="https://cdn.example.com" style="width: 100%; max-width: 400px;" />
                                    <p class="description">您的CDN域名，如：https://cdn.example.com（不要以斜杠结尾）</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                <p class="submit">
                    <button id="manus-save-settings" class="button button-primary">保存设置</button>
                </p>
            </div>

            <!-- 原图保留与清理设置，使用可折叠块包裹内容 -->
            <details>
                <summary><h2>安全性与原图清理</h2></summary>
                <div style="margin-top: 15px;">
                    <p>您可以选择是否保留原图，并对已转换的原图进行清理。</p>
                    <table class="form-table">
                        <tr>
                            <th scope="row">自动删除原图</th>
                            <td>
                                <input type="checkbox" id="manus-delete-original" />
                                <p class="description">勾选后，转换成功后立即删除原图以释放空间。默认保留原图以便手动恢复或清理。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">手动清理原图</th>
                            <td>
                                <label for="manus-cleanup-formats">格式筛选：</label>
                                <input type="text" id="manus-cleanup-formats" placeholder="jpg,png" style="width:120px;" />
                                <label for="manus-cleanup-size" style="margin-left:10px;">大小下限（MB）：</label>
                                <input type="number" id="manus-cleanup-size" min="0" value="0" style="width:80px;" />
                                <button id="manus-run-cleanup" class="button" style="margin-left:10px;">清理原图</button>
                                <p class="description">输入要删除的文件格式（逗号分隔）和最小文件大小。如果留空，则不限制格式或大小。</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </details>

            <!-- MIME 测试工具，使用可折叠块 -->
            <details>
                <summary><h2>WebP/AVIF MIME 测试工具</h2></summary>
                <div style="margin-top: 15px;">
                    <p>此工具可检测指定图片 URL 返回的 MIME 类型及缓存状态，帮助判断服务器是否正确配置了 WebP/AVIF 支持。</p>
                    <table class="form-table">
                        <tr>
                            <th scope="row">图片 URL</th>
                            <td>
                                <input type="url" id="manus-test-url" placeholder="https://example.com/wp-content/uploads/2024/07/image.webp" style="width:400px;" />
                                <button id="manus-run-mime-test" class="button" style="margin-left:10px;">检测 MIME</button>
                                <p id="manus-mime-test-result" class="description"></p>
                            </td>
                        </tr>
                    </table>
                </div>
            </details>

            <!-- Batch Conversion Section, wrapped in collapsible block -->
            <details>
                <summary><h2>批量转换现有图片</h2></summary>
                <div style="margin-top: 15px;">
                    <p>此工具将分批处理您媒体库中的所有符合条件的图片，转换为WebP格式以提高网站性能。</p>
                    
                    <div class="manus-stats">
                        <p>待处理图片总数: <span id="manus-total-images">计算中...</span></p>
                        <p>已处理: <span id="manus-processed-count">0</span></p>
                        <p>剩余: <span id="manus-remaining-count">计算中...</span></p>
                        <p>错误: <span id="manus-error-count">0</span></p>
                    </div>
                    
                    <div id="manus-progress-container" style="margin: 20px 0;">
                        <div id="manus-progress-bar" style="width: 100%; height: 30px; background-color: #ddd; border-radius: 15px; overflow: hidden;">
                            <div id="manus-progress-fill" style="width: 0%; height: 100%; background-color: #4CAF50; text-align: center; line-height: 30px; color: white; transition: width 0.3s ease;">0%</div>
                        </div>
                    </div>
                    
                    <p id="manus-status" style="font-weight: bold; color: #666;"></p>
                    
                    <p class="submit">
                        <button id="manus-start-conversion" class="button button-primary">开始批量转换</button>
                        <button id="manus-stop-conversion" class="button" style="display: none;">停止转换</button>
                    </p>
                </div>
            </details>
        </div>
        <?php
    }
    
    /**
     * AJAX: Get eligible images count (optimized with caching)
     */
    public function ajax_get_eligible_images_count() {
        check_ajax_referer( 'manus_image_optimizer_nonce', 'security' );
        
        // Check cache first to reduce database load
        $cache_key = 'manus_eligible_count';
        $cached_count = wp_cache_get( $cache_key, 'manus_optimizer' );
        
        if ( false !== $cached_count ) {
            wp_send_json_success( array( 'count' => $cached_count ) );
            return;
        }
        
        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif' ),
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'no_found_rows'  => false, // Need this for found_posts
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'     => array(
                'relation' => 'OR',
                array(
                    'key'     => '_manus_webp_converted',
                    'compare' => 'NOT EXISTS'
                ),
                array(
                    'key'     => '_manus_webp_converted',
                    'value'   => '0',
                    'compare' => '='
                )
            )
        );
        
        $query = new WP_Query( $args );
        $count = $query->found_posts;
        
        // Cache for 30 seconds to reduce database queries
        wp_cache_set( $cache_key, $count, 'manus_optimizer', 30 );
        
        wp_send_json_success( array( 'count' => $count ) );
    }
    
    /**
     * WP-Cron: Process image batch in background
     */
    public function process_image_batch_cron() {
        // Ensure only one cron process runs at a time and honour stop requests
        $stop_requested = get_option( 'manus_image_optimizer_stop_requested', 0 );
        if ( get_option( 'manus_image_optimizer_is_processing', 0 ) == 0 || $stop_requested == 1 ) {
            // Reset processing flags if a stop was requested
            if ( $stop_requested == 1 ) {
                update_option( 'manus_image_optimizer_is_processing', 0 );
                update_option( 'manus_image_optimizer_stop_requested', 0 );
            }
            return; // Not processing or stop requested, so do nothing
        }

        $batch_size      = get_option( 'manus_image_optimizer_batch_size', $this->batch_size );
        $quality         = get_option( 'manus_image_optimizer_quality', $this->default_quality );
        $delete_original = get_option( 'manus_image_optimizer_delete_original', 1 );
        $processor       = get_option( 'manus_image_optimizer_processor', 'gd' );
        $last_processed_id = get_option( 'manus_image_optimizer_last_processed_id', 0 );

        /*
         * Retrieve the target format and optional resize dimensions from the
         * stored options.  These values are required by convert_image_format()
         * but were not previously loaded in this context, which resulted in
         * undefined variable notices and prevented the batch from progressing.
         */
        $target_format = get_option( 'manus_image_optimizer_target_format', 'webp' );
        $max_width     = get_option( 'manus_image_optimizer_max_width', 0 );
        $max_height    = get_option( 'manus_image_optimizer_max_height', 0 );
        
        $processed_count_current_batch = 0;
        $error_count_current_batch = 0;
        $has_more = false;
        
        // Query for a batch of attachments that have not yet been converted.  We no
        // longer exclude a range of IDs, as that approach becomes inefficient on
        // large sites.  Instead we rely on the `_manus_webp_converted` meta key to
        // flag converted attachments and fetch the next batch of unconverted
        // attachments in ascending ID order.
        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif' ),
            'post_status'    => 'inherit',
            'posts_per_page' => $batch_size,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true, // Don't calculate total rows for performance
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'     => array(
                'relation' => 'OR',
                array(
                    'key'     => '_manus_webp_converted',
                    'compare' => 'NOT EXISTS'
                ),
                array(
                    'key'     => '_manus_webp_converted',
                    'value'   => '0',
                    'compare' => '='
                )
            )
        );
        
        $query = new WP_Query( $args );
        
        if ( $query->have_posts() ) {
            try {
                foreach ( $query->posts as $attachment_id ) {
                    $file_path = get_attached_file( $attachment_id );
                    if ( $file_path && file_exists( $file_path ) ) {
                        // Use the selected target format and optional resize dimensions
                        $converted = $this->convert_image_format( $file_path, $quality, $delete_original, $processor, $target_format, $max_width, $max_height );

                        if ( $converted ) {
                            // Update attachment file path
                            update_attached_file( $attachment_id, $converted['path'] );

                            // Remember the original file path for potential clean‑up.  Even
                            // when automatic deletion is enabled this meta value can be
                            // used for audit and manual removal later on.
                            update_post_meta( $attachment_id, '_manus_original_file', $file_path );
                            
                            // Update attachment metadata
                            $metadata = wp_generate_attachment_metadata( $attachment_id, $converted['path'] );
                            wp_update_attachment_metadata( $attachment_id, $metadata );

                            // Ensure the attachment's MIME type reflects the new file format.
                            // WordPress does not automatically update the post_mime_type
                            // column when the file extension changes.  Without this
                            // update the media library will continue to report
                            // image/jpeg or image/png even though the file is WebP/AVIF.
                            $new_mime = $converted['mime_type'];
                            // Use wp_update_post instead of direct database query to
                            // trigger necessary sanitisation and hooks.
                            wp_update_post( array( 'ID' => $attachment_id, 'post_mime_type' => $new_mime ) );
                            
                            // Mark as successfully converted
                            update_post_meta( $attachment_id, '_manus_webp_converted', 1 );
                            $processed_count_current_batch++;
                            
                            // Clear cache when images are converted
                            wp_cache_delete( 'manus_eligible_count', 'manus_optimizer' );
                        } else {
                            // Mark as failed and log error.  Use a non-zero sentinel value
                            // so the meta query does not continually select this attachment again.
                            update_post_meta( $attachment_id, '_manus_webp_converted', -1 );
                            $error_count_current_batch++;
                            $this->log_error( 'Failed to convert image: ' . $file_path, $file_path );
                        }
                    } else {
                        // File doesn't exist, mark as failed with sentinel
                        update_post_meta( $attachment_id, '_manus_webp_converted', -1 );
                        $error_count_current_batch++;
                    }
                    // Store the last processed ID for informational purposes; not used for pagination
                    update_option( 'manus_image_optimizer_last_processed_id', $attachment_id );
                }
            } catch ( Exception $e ) {
                // Catch unexpected exceptions so the cron does not halt silently.
                $this->log_error( 'Exception in process_image_batch_cron: ' . $e->getMessage() );
            }
            $has_more = ( $query->post_count == $batch_size );
        }

        // Update overall processed and error counts
        $total_processed = get_option( 'manus_image_optimizer_processed_count', 0 );
        $total_error = get_option( 'manus_image_optimizer_error_count', 0 );
        update_option( 'manus_image_optimizer_processed_count', $total_processed + $processed_count_current_batch );
        update_option( 'manus_image_optimizer_error_count', $total_error + $error_count_current_batch );

        // If there are more images to process and no stop request, reschedule the cron event
        if ( $has_more && get_option( 'manus_image_optimizer_is_processing', 0 ) == 1 && get_option( 'manus_image_optimizer_stop_requested', 0 ) == 0 ) {
            wp_schedule_single_event( time() + $this->batch_delay, 'manus_image_optimizer_batch_cron' );
        } else {
            // No more images or a stop was requested; reset flags so UI can reflect the final state
            update_option( 'manus_image_optimizer_is_processing', 0 );
            update_option( 'manus_image_optimizer_stop_requested', 0 );
            update_option( 'manus_image_optimizer_last_processed_id', 0 ); // Reset for next run
        }
    }
    
    /**
     * AJAX: Get plugin settings
     */
    public function ajax_get_plugin_settings() {
        check_ajax_referer( 'manus_image_optimizer_nonce', 'security' );
        
        wp_send_json_success( array(
            'quality' => get_option( 'manus_image_optimizer_quality', $this->default_quality ),
            'auto_convert' => get_option( 'manus_image_optimizer_auto_convert', 0 ),
            'batch_size' => get_option( 'manus_image_optimizer_batch_size', $this->batch_size ),
            'delete_original' => get_option( 'manus_image_optimizer_delete_original', 1 ),
            'processor' => get_option( 'manus_image_optimizer_processor', 'gd' ),
            'lazy_loading' => get_option( 'manus_image_optimizer_lazy_loading', 1 ),
            'cdn_enabled' => get_option( 'manus_image_optimizer_cdn_enabled', 0 ),
            'cdn_url' => get_option( 'manus_image_optimizer_cdn_url', '' ),
            'lazy_loading_threshold' => get_option( 'manus_image_optimizer_lazy_loading_threshold', '200px' )
        ) );
    }
    
    /**
     * AJAX: Update plugin settings
     */
    public function ajax_update_plugin_settings() {
        check_ajax_referer( 'manus_image_optimizer_nonce', 'security' );
        
        $settings = $_POST['settings'];
        
        update_option( 'manus_image_optimizer_quality', intval( $settings['quality'] ) );
        update_option( 'manus_image_optimizer_auto_convert', intval( $settings['auto_convert'] ) );
        update_option( 'manus_image_optimizer_batch_size', intval( $settings['batch_size'] ) );
        update_option( 'manus_image_optimizer_delete_original', intval( $settings['delete_original'] ) );
        update_option( 'manus_image_optimizer_processor', sanitize_text_field( $settings['processor'] ) );
        update_option( 'manus_image_optimizer_lazy_loading', intval( $settings['lazy_loading'] ) );
        update_option( 'manus_image_optimizer_cdn_enabled', intval( $settings['cdn_enabled'] ) );
        update_option( 'manus_image_optimizer_cdn_url', esc_url_raw( $settings['cdn_url'] ) );
        update_option( 'manus_image_optimizer_lazy_loading_threshold', sanitize_text_field( $settings['lazy_loading_threshold'] ) );
        
        wp_send_json_success( array( 'message' => 'Settings updated successfully' ) );
    }

    /**
     * AJAX: Start conversion (trigger cron)
     */
    public function ajax_start_conversion() {
        check_ajax_referer( 'manus_image_optimizer_nonce', 'security' );

        // Previously the plugin checked an internal activation flag before
        // starting a batch conversion.  This check has been removed because
        // the plugin is always active when installed.

        // Reset counts and processing flag if starting fresh
        update_option( 'manus_image_optimizer_processed_count', 0 );
        update_option( 'manus_image_optimizer_error_count', 0 );
        update_option( 'manus_image_optimizer_last_processed_id', 0 );
        update_option( 'manus_image_optimizer_stop_requested', 0 );
        update_option( 'manus_image_optimizer_is_processing', 1 );

        // Clear any existing scheduled cron events to avoid duplicates
        wp_clear_scheduled_hook( 'manus_image_optimizer_batch_cron' );

        /*
         * Immediately process the first batch instead of relying solely on WP‑Cron.  Some
         * hosting environments (like TasteWP) disable or delay cron execution, which
         * causes the conversion to appear “stuck” with no progress.  By invoking the
         * batch handler directly we ensure at least one batch is processed as soon
         * as the user clicks the start button.  The handler itself will schedule
         * subsequent cron events if there are more images and no stop is requested.
         */
        $this->process_image_batch_cron();

        // After processing the first batch, schedule a follow‑up event if processing is
        // still active.  The cron handler will reschedule itself as needed.  We use
        // $this->batch_delay to avoid hammering the server with back‑to‑back jobs.
        if ( get_option( 'manus_image_optimizer_is_processing', 0 ) == 1 ) {
            wp_schedule_single_event( time() + $this->batch_delay, 'manus_image_optimizer_batch_cron' );
        }

        wp_send_json_success( array( 'message' => 'Conversion started and first batch processed.' ) );
    }

    /**
     * AJAX: Stop conversion
     */
    public function ajax_stop_conversion() {
        check_ajax_referer( 'manus_image_optimizer_nonce', 'security' );

        // Signal the cron handler to stop gracefully.  Both options are set so
        // that the UI immediately reflects the stopped state and the cron
        // handler aborts further processing/rescheduling on its next run.
        update_option( 'manus_image_optimizer_stop_requested', 1 );
        update_option( 'manus_image_optimizer_is_processing', 0 );
        /*
         * Reset progress counters when the user stops the conversion.  Without
         * clearing these values the UI will continue to display stale
         * processed/error counts and a partially filled progress bar on
         * subsequent page loads.  Clearing them here ensures the next
         * conversion session starts from a clean state and the status bar
         * properly reflects zero progress.  The last processed ID is also
         * reset to zero so that future runs do not attempt to resume
         * processing from an old position.
         */
        update_option( 'manus_image_optimizer_processed_count', 0 );
        update_option( 'manus_image_optimizer_error_count', 0 );
        update_option( 'manus_image_optimizer_last_processed_id', 0 );
        wp_clear_scheduled_hook( 'manus_image_optimizer_batch_cron' );

        wp_send_json_success( array( 'message' => 'Conversion stopped.' ) );
    }

    /**
     * AJAX: Get conversion progress
     */
    public function ajax_get_conversion_progress() {
        check_ajax_referer( 'manus_image_optimizer_nonce', 'security' );

        $processed_count = get_option( 'manus_image_optimizer_processed_count', 0 );
        $error_count     = get_option( 'manus_image_optimizer_error_count', 0 );
        $is_processing   = get_option( 'manus_image_optimizer_is_processing', 0 );

        /*
         * If a stop request has been issued we should treat the conversion as
         * stopped for UI purposes.  Do not reset the stop flag here.  By
         * leaving the flag set, subsequent page loads will continue to
         * recognise that the process has been halted and will not
         * accidentally re‑enter a processing state.  The stop flag will be
         * cleared either by the cron handler once it acknowledges the
         * request or when the user explicitly starts a new conversion.
         */
        $stop_requested = get_option( 'manus_image_optimizer_stop_requested', 0 );
        if ( $stop_requested == 1 ) {
            // Force local state to treat processing as stopped
            $is_processing = 0;
        }

        /*
         * In some hosting environments WP‑Cron is disabled or delayed, which can
         * cause batch conversion to hang after the first batch (often at 50%).
         * To ensure progress continues, trigger the batch handler on each
         * progress poll if processing is active.  The handler itself will
         * check stop flags and whether there is more work to do before
         * performing any operations.  This effectively uses the browser’s
         * polling as a fallback scheduler when cron is unreliable.
         */
        if ( $is_processing == 1 ) {
            $this->process_image_batch_cron();
        }

        wp_send_json_success( array(
            'processed_count' => $processed_count,
            'error_count'     => $error_count,
            'is_processing'   => $is_processing
        ) );
    }

    /**
     * AJAX: Check available image processing libraries
     */
    public function ajax_check_image_libs() {
        check_ajax_referer( 'manus_image_optimizer_nonce', 'security' );

        $gd_available = function_exists( 'gd_info' ) && function_exists( 'imagewebp' );
        $imagick_available = class_exists( 'Imagick' ) && method_exists( 'Imagick', 'readImage' ) && method_exists( 'Imagick', 'setImageFormat' );

        wp_send_json_success( array(
            'gd_available'      => $gd_available,
            'imagick_available' => $imagick_available
        ) );
    }

    /*
     * The plugin previously exposed AJAX handlers to toggle an internal
     * activation flag from its settings page.  Those handlers have been
     * removed because the plugin is now always active while installed.  Any
     * client‑side attempts to call these actions will simply fail with no
     * effect.  This stub remains for backwards compatibility to avoid
     * fatal errors if other code still references these methods.
     */
    public function ajax_activate_plugin() {
        wp_send_json_error( array( 'message' => 'Activation toggling is no longer required.' ) );
    }
    public function ajax_deactivate_plugin() {
        wp_send_json_error( array( 'message' => 'Deactivation toggling is no longer required.' ) );
    }

    /**
     * Attempt to append WebP/AVIF MIME type directives to the site's
     * .htaccess file when running on Apache.  If the server is not Apache
     * (for example Nginx) this method does nothing.  The AddType lines
     * ensure that browsers receive the correct Content-Type header when
     * requesting .webp or .avif files.  Without this directive Apache
     * may serve converted images with a default text/html or application/octet-stream
     * MIME type which prevents the browser from displaying them.  This method
     * will only append the directives if they are not already present and
     * if the file is writable.  Errors are silently ignored; administrators
     * can manually adjust their server configuration if automatic updates
     * are not possible.
     */
    private function maybe_add_webp_htaccess() {
        // Only proceed if the server appears to be Apache
        if ( isset( $_SERVER['SERVER_SOFTWARE'] ) && stripos( $_SERVER['SERVER_SOFTWARE'], 'apache' ) !== false ) {
            $htaccess_path = ABSPATH . '.htaccess';
            $directives = "AddType image/webp .webp\nAddType image/avif .avif\n";
            // If .htaccess exists and is writable append directives if missing
            if ( file_exists( $htaccess_path ) && is_writable( $htaccess_path ) ) {
                $contents = file_get_contents( $htaccess_path );
                if ( strpos( $contents, 'AddType image/webp' ) === false ) {
                    // Append with a marker comment
                    $append  = "\n# Added by Manus Image Optimizer plugin\n" . $directives;
                    @file_put_contents( $htaccess_path, $append, FILE_APPEND | LOCK_EX );
                }
            }
            // If .htaccess does not exist but the upload directory is writable, attempt to create one
            if ( ! file_exists( $htaccess_path ) ) {
                $upload_dir = wp_upload_dir();
                if ( isset( $upload_dir['basedir'] ) && is_writable( $upload_dir['basedir'] ) ) {
                    $htaccess_path = trailingslashit( $upload_dir['basedir'] ) . '.htaccess';
                    if ( ! file_exists( $htaccess_path ) ) {
                        @file_put_contents( $htaccess_path, "# Added by Manus Image Optimizer plugin\n" . $directives );
                    }
                }
            }
        }
    }

    /**
     * AJAX: Clean up original images that were retained after conversion.
     *
     * This endpoint accepts optional parameters to filter deletions by file
     * format(s) and size.  The `formats` parameter should be a comma‑separated
     * list of lower‑case extensions (e.g. "jpg,png").  The `size` parameter
     * represents the minimum file size in megabytes.  If provided, only
     * originals equal or larger than this size will be removed.  The
     * endpoint will return counts of deleted and kept files.  Only
     * attachments that have been marked as successfully converted
     * (`_manus_webp_converted` meta = 1) are considered.
     */
    public function ajax_cleanup_original_images() {
        check_ajax_referer( 'manus_image_optimizer_nonce', 'security' );

        $formats = isset( $_POST['formats'] ) ? sanitize_text_field( $_POST['formats'] ) : '';
        $size_mb = isset( $_POST['size'] ) ? floatval( $_POST['size'] ) : 0;
        $size_bytes = $size_mb > 0 ? ( $size_mb * 1024 * 1024 ) : 0;

        // Normalise formats into an array of lower‑case extensions
        $format_list = array();
        if ( ! empty( $formats ) ) {
            $parts = explode( ',', $formats );
            foreach ( $parts as $p ) {
                $p = trim( strtolower( $p ) );
                if ( $p !== '' ) {
                    $format_list[] = $p;
                }
            }
        }

        $deleted = 0;
        $kept    = 0;

        $args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true, // Performance optimization
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'     => array(
                array(
                    'key'   => '_manus_webp_converted',
                    'value' => '1',
                    'compare' => '='
                )
            )
        );
        $query = new WP_Query( $args );
        if ( $query->have_posts() ) {
            foreach ( $query->posts as $attachment_id ) {
                $converted_path = get_attached_file( $attachment_id );
                $orig_path = get_post_meta( $attachment_id, '_manus_original_file', true );
                // Derive original path if meta not available
                if ( empty( $orig_path ) && $converted_path ) {
                    $info = pathinfo( $converted_path );
                    $base = $info['filename'];
                    $dir  = $info['dirname'];
                    $candidates = array( 'jpg', 'jpeg', 'png', 'gif' );
                    foreach ( $candidates as $ext ) {
                        $candidate = $dir . '/' . $base . '.' . $ext;
                        if ( file_exists( $candidate ) ) {
                            $orig_path = $candidate;
                            break;
                        }
                    }
                }
                if ( ! empty( $orig_path ) && file_exists( $orig_path ) ) {
                    $ext  = strtolower( pathinfo( $orig_path, PATHINFO_EXTENSION ) );
                    $size = filesize( $orig_path );
                    $match_format = empty( $format_list ) || in_array( $ext, $format_list );
                    $match_size   = ( $size_bytes <= 0 ) || ( $size >= $size_bytes );
                    if ( $match_format && $match_size ) {
                        // Attempt deletion; track failures as kept
                        if ( @unlink( $orig_path ) ) {
                            $deleted++;
                        } else {
                            $kept++;
                        }
                    } else {
                        $kept++;
                    }
                }
            }
        }
        wp_send_json_success( array( 'deleted' => $deleted, 'kept' => $kept ) );
    }

    /**
     * AJAX: Test the MIME type returned by a given URL.  Useful for
     * verifying that converted images are served with the correct
     * Content‑Type header (image/webp or image/avif) rather than
     * text/html.  Accepts a `url` POST parameter and returns
     * information about the response.  Note: depending on your server
     * configuration remote requests may be blocked; in such cases
     * the request will fail with an error message.
     */
    public function ajax_test_image_mime() {
        check_ajax_referer( 'manus_image_optimizer_nonce', 'security' );
        $url = isset( $_POST['url'] ) ? esc_url_raw( $_POST['url'] ) : '';
        if ( empty( $url ) ) {
            wp_send_json_error( array( 'message' => '未提供要检测的 URL。' ) );
        }
        // Make a HEAD request to avoid downloading the full image
        $response = wp_remote_head( $url, array( 'timeout' => 10, 'redirection' => 3 ) );
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => $response->get_error_message() ) );
        }
        $headers = wp_remote_retrieve_headers( $response );
        $content_type = '';
        $content_length = 0;
        $cache_header = '';
        if ( isset( $headers['content-type'] ) ) {
            $content_type = $headers['content-type'];
        }
        if ( isset( $headers['content-length'] ) ) {
            $content_length = (int) $headers['content-length'];
        }
        // Cache headers vary by host; inspect common ones
        $cache_keys = array( 'x-cache', 'cf-cache-status', 'cache-control', 'age' );
        foreach ( $cache_keys as $key ) {
            if ( isset( $headers[ $key ] ) ) {
                $cache_header .= $key . ': ' . $headers[ $key ] . '\n';
            }
        }
        $is_webp = ( stripos( $content_type, 'image/webp' ) !== false );
        $is_avif = ( stripos( $content_type, 'image/avif' ) !== false );
        wp_send_json_success( array(
            'url'            => $url,
            'content_type'   => $content_type,
            'content_length' => $content_length,
            'cache_header'   => trim( $cache_header ),
            'is_webp'        => $is_webp,
            'is_avif'        => $is_avif
        ) );
    }
}

add_action( 'plugins_loaded', 'manus_image_optimizer_init' );

function manus_image_optimizer_init() {
    new ManusImageOptimizer();
}

