package services

import (
	"fmt"
	"os"
	"path/filepath"
)

// writeFileAtomic writes data to a file atomically by writing to a temp file
// and renaming. This prevents partial writes from corrupting config files.
func writeFileAtomic(path string, data []byte, perm os.FileMode) error {
	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0755); err != nil {
		return fmt.Errorf("create directory %s: %w", dir, err)
	}

	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, data, perm); err != nil {
		return fmt.Errorf("write temp file %s: %w", tmp, err)
	}

	if err := os.Rename(tmp, path); err != nil {
		os.Remove(tmp) // cleanup on failure
		return fmt.Errorf("rename %s to %s: %w", tmp, path, err)
	}

	// os.WriteFile respects umask (e.g. 0777 → 0755 with umask 0022).
	// Chmod ignores umask, ensuring the exact permissions we need.
	if err := os.Chmod(path, perm); err != nil {
		return fmt.Errorf("chmod %s: %w", path, err)
	}

	return nil
}
