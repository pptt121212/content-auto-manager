document.addEventListener('DOMContentLoaded', function() {
    // 全选功能
    var selectAllBtn = document.getElementById('select-all-categories');
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            var checkboxes = document.querySelectorAll('.category-checkbox');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = true;
            });
        });
    }

    // 全不选功能
    var deselectAllBtn = document.getElementById('deselect-all-categories');
    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', function() {
            var checkboxes = document.querySelectorAll('.category-checkbox');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = false;
            });
        });
    }
    
    // 只选择父分类
    var toggleParentBtn = document.getElementById('toggle-parent-categories');
    if (toggleParentBtn) {
        toggleParentBtn.addEventListener('click', function() {
            var checkboxes = document.querySelectorAll('.category-checkbox');
            checkboxes.forEach(function(checkbox) {
                // 只选择顶级分类（level=0）
                checkbox.checked = checkbox.getAttribute('data-level') === '0';
            });
        });
    }
    
    // 搜索功能
    var searchInput = document.getElementById('category-search');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var searchTerm = this.value.toLowerCase();
            var categoryItems = document.querySelectorAll('.category-item');

            categoryItems.forEach(function(item) {
                var categoryName = item.textContent.toLowerCase();
                if (categoryName.includes(searchTerm)) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    }

    // 启用分类过滤开关功能
    var enableFilterCheckbox = document.querySelector('input[name="yali_enable_category_filter"]');
    var categorySelectionContainer = document.getElementById('category-selection-container');
    if (enableFilterCheckbox && categorySelectionContainer) {
        enableFilterCheckbox.addEventListener('change', function() {
            categorySelectionContainer.style.display = this.checked ? 'block' : 'none';
        });
    }
});
