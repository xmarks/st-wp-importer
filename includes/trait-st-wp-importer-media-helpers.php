<?php

/**
 * Shared helpers for rewriting/importing media references in meta and block data.
 */
trait St_Wp_Importer_Media_Helpers {

    /**
     * Detect and import media referenced by ACF image/file fields.
     *
     * Handles common shapes:
     * - plain attachment ID (int or numeric string)
     * - associative array with ID / url / sizes (ACF image return formats)
     * - serialized data already parsed by maybe_unserialize
     *
     * Returns imported count and possibly rewritten value (e.g., new attachment ID).
     */
    private function maybe_import_acf_media_value( string $key, $value, array $settings, bool $dry_run, array $acf_value_keys ): array {
        $result = array(
            'value'    => $value,
            'imported' => 0,
        );

        // Skip non-ACF meta keys (fast exit).
        if ( ! in_array( $key, $acf_value_keys, true ) ) {
            return $result;
        }

        // Attachment id form.
        if ( is_numeric( $value ) ) {
            $src_id = (int) $value;
            if ( $src_id > 0 ) {
                $imported_id = $this->media->import_attachment_by_id( $src_id, $settings, $dry_run );
                if ( $imported_id ) {
                    $result['value']    = $imported_id;
                    $result['imported'] = 1;
                }
            }
            return $result;
        }

        // Direct URL string to uploads.
        if ( is_string( $value ) && strpos( $value, '/wp-content/uploads/' ) !== false ) {
            $imported_id = $this->media->import_attachment_from_url( $value, $settings, $dry_run );
            if ( $imported_id ) {
                $result['value']    = $imported_id;
                $result['imported'] = 1;
            }
            return $result;
        }

        // Array form from ACF image/file (return = array).
        if ( is_array( $value ) ) {
            // Gallery-style: flat list of IDs.
            if ( $this->is_list_of_scalars( $value ) ) {
                $new_gallery = array();
                $changed     = false;
                foreach ( $value as $item ) {
                    $new_item = $item;
                    if ( is_numeric( $item ) ) {
                        $new_id = $this->media->import_attachment_by_id( (int) $item, $settings, $dry_run );
                        if ( $new_id ) {
                            $new_item = $new_id;
                            $result['imported']++;
                            $changed = true;
                        }
                    } elseif ( is_string( $item ) && strpos( $item, '/wp-content/uploads/' ) !== false ) {
                        $new_id = $this->media->import_attachment_from_url( $item, $settings, $dry_run );
                        if ( $new_id ) {
                            $new_item = $new_id;
                            $result['imported']++;
                            $changed = true;
                        }
                    }
                    $new_gallery[] = $new_item;
                }
                if ( $changed ) {
                    $result['value'] = $new_gallery;
                }
                return $result;
            }

            // Typical keys: id / ID, url, sizes, filename, filesize, mime_type.
            $src_id = 0;
            if ( isset( $value['id'] ) && is_numeric( $value['id'] ) ) {
                $src_id = (int) $value['id'];
            } elseif ( isset( $value['ID'] ) && is_numeric( $value['ID'] ) ) {
                $src_id = (int) $value['ID'];
            }

            if ( $src_id > 0 ) {
                $imported_id = $this->media->import_attachment_by_id( $src_id, $settings, $dry_run );
                if ( $imported_id ) {
                    $result['value']    = $imported_id;
                    $result['imported'] = 1;
                    return $result;
                }
            }

            // Fallback: try URL.
            if ( ! empty( $value['url'] ) && is_string( $value['url'] ) ) {
                $imported_id = $this->media->import_attachment_from_url( $value['url'], $settings, $dry_run );
                if ( $imported_id ) {
                    $result['value']    = $imported_id;
                    $result['imported'] = 1;
                    return $result;
                }
            }

            // Fallback: try sizes array URLs (pick largest available).
            if ( ! empty( $value['sizes'] ) && is_array( $value['sizes'] ) ) {
                $urls = array_values( array_filter( $value['sizes'], 'is_string' ) );
                foreach ( $urls as $size_url ) {
                    $imported_id = $this->media->import_attachment_from_url( $size_url, $settings, $dry_run );
                    if ( $imported_id ) {
                        $result['value']    = $imported_id;
                        $result['imported'] = 1;
                        return $result;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Simple check for a zero-indexed list of scalar values.
     */
    private function is_list_of_scalars( array $value ): bool {
        if ( array_values( $value ) !== $value ) {
            return false;
        }
        foreach ( $value as $item ) {
            if ( is_array( $item ) || is_object( $item ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Heuristic for media-like meta keys.
     */
    private function looks_like_media_meta_key( string $key ): bool {
        return (bool) preg_match( '/(image|logo|photo|thumbnail|thumb|file|attachment)/i', $key );
    }

    /**
     * Generic media import for non-ACF meta values.
     */
    private function maybe_import_generic_media_value( string $key, $value, array $settings, bool $dry_run ): array {
        $result = array(
            'value'    => $value,
            'imported' => 0,
        );

        // Numeric attachment id assumption.
        if ( is_numeric( $value ) ) {
            $src_id = (int) $value;
            if ( $src_id > 0 ) {
                $imported_id = $this->media->import_attachment_by_id( $src_id, $settings, $dry_run );
                if ( $imported_id ) {
                    $result['value']    = $imported_id;
                    $result['imported'] = 1;
                }
            }
            return $result;
        }

        // URL to source uploads.
        if ( is_string( $value ) && strpos( $value, '/wp-content/uploads/' ) !== false ) {
            $imported_id = $this->media->import_attachment_from_url( $value, $settings, $dry_run );
            if ( $imported_id ) {
                $result['value']    = $imported_id;
                $result['imported'] = 1;
            }
            return $result;
        }

        // Array form similar to ACF image/file return.
        if ( is_array( $value ) ) {
            $src_id = 0;
            if ( isset( $value['id'] ) && is_numeric( $value['id'] ) ) {
                $src_id = (int) $value['id'];
            } elseif ( isset( $value['ID'] ) && is_numeric( $value['ID'] ) ) {
                $src_id = (int) $value['ID'];
            }

            if ( $src_id > 0 ) {
                $imported_id = $this->media->import_attachment_by_id( $src_id, $settings, $dry_run );
                if ( $imported_id ) {
                    $result['value']    = $imported_id;
                    $result['imported'] = 1;
                    return $result;
                }
            }

            if ( ! empty( $value['url'] ) && is_string( $value['url'] ) ) {
                $imported_id = $this->media->import_attachment_from_url( $value['url'], $settings, $dry_run );
                if ( $imported_id ) {
                    $result['value']    = $imported_id;
                    $result['imported'] = 1;
                    return $result;
                }
            }
        }

        return $result;
    }
}

