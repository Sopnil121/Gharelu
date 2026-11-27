<?php
function renderPagination($totalPages, $currentPage, $queryParams = []) {
    if ($totalPages <= 1) return '';
    
    $queryString = '';
    foreach ($queryParams as $key => $value) {
        if ($key !== 'page' && $value !== '' && $value !== 0 && $value !== '0') {
            $queryString .= '&' . urlencode($key) . '=' . urlencode($value);
        }
    }
    
    $html = '<div class="pagination-container">';
    $html .= '<div class="page-info">Page ' . $currentPage . ' of ' . $totalPages . '</div>';
    
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);
    
    if ($startPage > 1) {
        $html .= '<a href="?page=1' . $queryString . '" class="page-btn">1</a>';
        if ($startPage > 2) {
            $html .= '<span class="page-dots">...</span>';
        }
    }
    
    for ($i = $startPage; $i <= $endPage; $i++) {
        $activeClass = ($i === $currentPage) ? ' page-active' : '';
        $html .= '<a href="?page=' . $i . $queryString . '" class="page-btn' . $activeClass . '">' . $i . '</a>';
    }
    
    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) {
            $html .= '<span class="page-dots">...</span>';
        }
        $html .= '<a href="?page=' . $totalPages . $queryString . '" class="page-btn">' . $totalPages . '</a>';
    }
    
    if ($currentPage < $totalPages) {
        $html .= '<a href="?page=' . ($currentPage + 1) . $queryString . '" class="page-btn page-next"><i class="fas fa-chevron-right"></i></a>';
    } else {
        $html .= '<span class="page-btn page-next disabled"><i class="fas fa-chevron-right"></i></span>';
    }
    
    $html .= '</div>';
    return $html;
}
?>
