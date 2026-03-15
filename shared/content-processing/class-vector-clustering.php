<?php
/**
 * A pure PHP implementation of the K-Means clustering algorithm for vectors.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_VectorClustering {

    private $num_clusters;
    private $max_iterations;
    private $centroids;
    private $assignments; // Map of vector index to cluster index
    private $vector_magnitudes; // 预计算的向量模长
    private $centroid_magnitudes; // 预计算的质心模长
    private $start_time; // 开始时间，用于超时检测
    private $max_execution_time; // 最大执行时间（秒）

    public function __construct($num_clusters, $max_iterations = 50, $max_execution_time = 1800) {
        $this->num_clusters = $num_clusters;
        $this->max_iterations = $max_iterations;
        $this->max_execution_time = $max_execution_time; // 30分钟默认限制
        $this->centroids = [];
        $this->assignments = [];
        $this->vector_magnitudes = [];
        $this->centroid_magnitudes = [];
        $this->start_time = time();
    }

    /**
     * Main method to perform clustering.
     *
     * @param array $vectors An array of vectors (each vector is an array of floats).
     * @return array An array containing ['assignments' => [...], 'centroids' => [...]].
     */
    public function cluster(array $vectors) {
        if (count($vectors) < $this->num_clusters) {
            // Not enough data to form the requested number of clusters
            return false;
        }

        // 预计算所有向量的模长以提高性能
        $this->precompute_vector_magnitudes($vectors);
        
        $this->initialize_centroids($vectors);
        
        // 初始化质心后立即计算质心的模长
        $this->precompute_centroid_magnitudes();

        for ($i = 0; $i < $this->max_iterations; $i++) {
            // 检查执行时间，防止超时
            if ($this->is_timeout()) {
                error_log("ContentAuto: Clustering timeout after {$i} iterations");
                break;
            }

            $assignments_changed = $this->assign_vectors_to_centroids($vectors);

            if (!$assignments_changed) {
                // Convergence reached
                break;
            }

            $this->update_centroids($vectors);
            
            // 每10次迭代检查一次内存使用
            if ($i % 10 == 0 && $this->is_memory_limit_approaching()) {
                error_log("ContentAuto: Memory limit approaching, stopping clustering at iteration {$i}");
                break;
            }
        }

        return [
            'assignments' => $this->assignments,
            'centroids' => $this->centroids,
        ];
    }

    /**
     * Initializes centroids by picking random vectors from the dataset.
     */
    private function initialize_centroids(array $vectors) {
        $this->centroids = [];
        $random_keys = array_rand($vectors, $this->num_clusters);
        
        // 处理array_rand返回值：当num_clusters=1时返回单个键，否则返回数组
        if ($this->num_clusters == 1) {
            $this->centroids[] = $vectors[$random_keys];
        } else {
            foreach ($random_keys as $key) {
                $this->centroids[] = $vectors[$key];
            }
        }
    }

    /**
     * Assigns each vector to the closest centroid.
     * @return bool True if any vector changed its cluster assignment, false otherwise.
     */
    private function assign_vectors_to_centroids(array $vectors) {
        $assignments_changed = false;
        $new_assignments = [];

        foreach ($vectors as $vector_index => $vector) {
            // 每处理100个向量检查一次超时
            if ($vector_index % 100 == 0 && $this->is_timeout()) {
                error_log("ContentAuto: Timeout during vector assignment at vector {$vector_index}");
                break;
            }

            $closest_centroid_index = $this->find_closest_centroid_for_vector($vector, $vector_index);
            $new_assignments[$vector_index] = $closest_centroid_index;

            if (!isset($this->assignments[$vector_index]) || $this->assignments[$vector_index] !== $closest_centroid_index) {
                $assignments_changed = true;
            }
        }

        $this->assignments = $new_assignments;
        return $assignments_changed;
    }

    /**
     * 为特定向量找到最近的质心（使用预计算的模长）
     */
    private function find_closest_centroid_for_vector(array $vector, $vector_index) {
        $min_distance = INF;
        $closest_centroid_index = -1;
        $vector_magnitude = $this->vector_magnitudes[$vector_index];

        foreach ($this->centroids as $centroid_index => $centroid) {
            $distance = $this->calculate_fast_cosine_distance($vector, $centroid, $vector_magnitude, $centroid_index);
            if ($distance < $min_distance) {
                $min_distance = $distance;
                $closest_centroid_index = $centroid_index;
            }
        }

        return $closest_centroid_index;
    }

    /**
     * 使用预计算模长的快速余弦距离计算
     */
    private function calculate_fast_cosine_distance($vector, $centroid, $vector_magnitude, $centroid_index) {
        $dot_product = 0.0;
        $dimensions = count($vector);
        
        for ($i = 0; $i < $dimensions; $i++) {
            $dot_product += $vector[$i] * $centroid[$i];
        }
        
        $centroid_magnitude = $this->centroid_magnitudes[$centroid_index] ?? $this->calculate_magnitude($centroid);
        
        if ($vector_magnitude == 0.0 || $centroid_magnitude == 0.0) {
            return 1.0; // 最大距离
        }
        
        $similarity = $dot_product / ($vector_magnitude * $centroid_magnitude);
        return 1.0 - $similarity; // 转换为距离
    }

    /**
     * Recalculates centroids based on the mean of the vectors in each cluster.
     */
    private function update_centroids(array $vectors) {
        $new_centroids = [];
        $cluster_sums = [];
        $cluster_counts = array_fill(0, $this->num_clusters, 0);
        $dimensions = count($vectors[0]);

        // Initialize sums array
        for ($i = 0; $i < $this->num_clusters; $i++) {
            $cluster_sums[$i] = array_fill(0, $dimensions, 0.0);
        }

        // Sum up vectors in each cluster
        foreach ($this->assignments as $vector_index => $cluster_index) {
            for ($d = 0; $d < $dimensions; $d++) {
                $cluster_sums[$cluster_index][$d] += $vectors[$vector_index][$d];
            }
            $cluster_counts[$cluster_index]++;
        }

        // Calculate the new mean for each cluster
        for ($i = 0; $i < $this->num_clusters; $i++) {
            if ($cluster_counts[$i] > 0) {
                $mean_vector = [];
                for ($d = 0; $d < $dimensions; $d++) {
                    $mean_vector[$d] = $cluster_sums[$i][$d] / $cluster_counts[$i];
                }
                $new_centroids[$i] = $mean_vector;
            } else {
                // This cluster is empty. Re-initialize its centroid to a random vector
                // to avoid losing a cluster.
                $new_centroids[$i] = $vectors[array_rand($vectors)];
            }
        }

        $this->centroids = $new_centroids;
        
        // 重新计算质心的模长
        $this->precompute_centroid_magnitudes();
    }

    /**
     * Finds the index of the closest centroid to a given vector using cosine distance.
     */
    private function find_closest_centroid(array $vector, array $centroids) {
        $min_distance = INF;
        $closest_centroid_index = -1;

        foreach ($centroids as $index => $centroid) {
            $distance = $this->calculate_cosine_distance($vector, $centroid, $index);
            if ($distance < $min_distance) {
                $min_distance = $distance;
                $closest_centroid_index = $index;
            }
        }

        return $closest_centroid_index;
    }

    /**
     * 预计算所有向量的模长
     */
    private function precompute_vector_magnitudes(array $vectors) {
        $this->vector_magnitudes = [];
        foreach ($vectors as $index => $vector) {
            $magnitude = 0.0;
            foreach ($vector as $component) {
                $magnitude += $component * $component;
            }
            $this->vector_magnitudes[$index] = sqrt($magnitude);
        }
    }

    /**
     * 预计算质心的模长
     */
    private function precompute_centroid_magnitudes() {
        $this->centroid_magnitudes = [];
        foreach ($this->centroids as $index => $centroid) {
            $magnitude = 0.0;
            foreach ($centroid as $component) {
                $magnitude += $component * $component;
            }
            $this->centroid_magnitudes[$index] = sqrt($magnitude);
        }
    }

    /**
     * 使用预计算模长的快速余弦距离计算（已弃用，使用calculate_fast_cosine_distance）
     */
    private function calculate_cosine_distance($vector, $centroid, $centroid_index) {
        $dot_product = 0.0;
        $dimensions = count($vector);
        
        for ($i = 0; $i < $dimensions; $i++) {
            $dot_product += $vector[$i] * $centroid[$i];
        }
        
        $vector_magnitude = $this->calculate_magnitude($vector);
        $centroid_magnitude = $this->centroid_magnitudes[$centroid_index] ?? $this->calculate_magnitude($centroid);
        
        if ($vector_magnitude == 0.0 || $centroid_magnitude == 0.0) {
            return 1.0; // 最大距离
        }
        
        $similarity = $dot_product / ($vector_magnitude * $centroid_magnitude);
        return 1.0 - $similarity; // 转换为距离
    }

    /**
     * 计算单个向量的模长
     */
    private function calculate_magnitude(array $vector) {
        $magnitude = 0.0;
        foreach ($vector as $component) {
            $magnitude += $component * $component;
        }
        return sqrt($magnitude);
    }

    /**
     * 检查是否超时
     */
    private function is_timeout() {
        return (time() - $this->start_time) > $this->max_execution_time;
    }

    /**
     * 检查内存使用是否接近限制
     */
    private function is_memory_limit_approaching() {
        $memory_limit = $this->get_memory_limit_bytes();
        $current_usage = memory_get_usage(true);
        
        // 如果当前使用超过限制的80%，认为接近限制
        return $current_usage > ($memory_limit * 0.8);
    }

    /**
     * 获取PHP内存限制（字节）
     */
    private function get_memory_limit_bytes() {
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit == -1) {
            return PHP_INT_MAX; // 无限制
        }
        
        $unit = strtolower(substr($memory_limit, -1));
        $value = (int) $memory_limit;
        
        switch ($unit) {
            case 'g': return $value * 1024 * 1024 * 1024;
            case 'm': return $value * 1024 * 1024;
            case 'k': return $value * 1024;
            default: return $value;
        }
    }
}
