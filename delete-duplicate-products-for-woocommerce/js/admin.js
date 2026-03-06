jQuery(document).ready(function($) {
    var $form = $('#cptsm2-delete-form');

    // -------------------------------------------------------------------------
    // BUG FIX: Use event delegation instead of binding to every checkbox.
    // This fixes the slow checkbox response with large product catalogs (>10K).
    // -------------------------------------------------------------------------

    // "Select All" checkbox per group — delegate to the form container
    $form.on('change', '.cb-select-all', function() {
        var isChecked = $(this).prop('checked');
        $(this).closest('table').find('input[name="products[]"]').prop('checked', isChecked);
    });

    // Individual product checkboxes — update the group "Select All" state
    $form.on('change', 'input[name="products[]"]', function() {
        var $table      = $(this).closest('table');
        var $checkboxes = $table.find('input[name="products[]"]');
        var allChecked  = $checkboxes.length === $checkboxes.filter(':checked').length;
        $table.find('.cb-select-all').prop('checked', allChecked);
    });

    // -------------------------------------------------------------------------
    // FREE FEATURE: Select All — Keep Newest / Keep Oldest (page-level)
    // Acts on every duplicate group visible on the current page.
    // -------------------------------------------------------------------------

    $(document).on('click', '#cptsm2-page-keep-newest', function() {
        var $btn = $(this);
        var isActive = $btn.data('active'); // Revisamos si ya fue presionado

        if (isActive) {
            // Si ya estaba activo, desmarcamos todos los checkboxes
            $('.cptsm2-duplicate-group').find('input[type="checkbox"]').prop('checked', false);
            $btn.data('active', false); // Cambiamos el estado a inactivo
        } else {
            // Si no estaba activo, aplicamos tu lógica original
            $('.cptsm2-duplicate-group').each(function() {
                var $rows = $(this).find('tbody tr');
                $rows.find('input[name="products[]"]').prop('checked', true);
                $rows.first().find('input[name="products[]"]').prop('checked', false);
                $(this).find('.cb-select-all').prop('checked', false);
            });
            
            $btn.data('active', true); // Marcamos este botón como activo
            $('#cptsm2-page-keep-oldest').data('active', false); // Reiniciamos el otro botón por si acaso
        }
    });

    $(document).on('click', '#cptsm2-page-keep-oldest', function() {
        var $btn = $(this);
        var isActive = $btn.data('active'); // Revisamos si ya fue presionado

        if (isActive) {
            // Si ya estaba activo, desmarcamos todos los checkboxes
            $('.cptsm2-duplicate-group').find('input[type="checkbox"]').prop('checked', false);
            $btn.data('active', false); // Cambiamos el estado a inactivo
        } else {
            // Si no estaba activo, aplicamos tu lógica original
            $('.cptsm2-duplicate-group').each(function() {
                var $rows = $(this).find('tbody tr');
                $rows.find('input[name="products[]"]').prop('checked', true);
                $rows.last().find('input[name="products[]"]').prop('checked', false);
                $(this).find('.cb-select-all').prop('checked', false);
            });
            
            $btn.data('active', true); // Marcamos este botón como activo
            $('#cptsm2-page-keep-newest').data('active', false); // Reiniciamos el otro botón por si acaso
        }
    });

    // -------------------------------------------------------------------------
    // PRO FEATURE: Keep Newest / Keep Oldest (per-group)
    // Products are sorted DATE DESC (newest first) so:
    //   Keep Newest  = select all rows EXCEPT the first
    //   Keep Oldest  = select all rows EXCEPT the last
    // -------------------------------------------------------------------------

    $(document).on('click', '.cptsm2-keep-newest', function() {
        var $group = $(this).closest('.cptsm2-duplicate-group');
        var $rows  = $group.find('tbody tr');
        $rows.find('input[name="products[]"]').prop('checked', true);
        $rows.first().find('input[name="products[]"]').prop('checked', false);
        $group.find('.cb-select-all').prop('checked', false);
    });

    $(document).on('click', '.cptsm2-keep-oldest', function() {
        var $group = $(this).closest('.cptsm2-duplicate-group');
        var $rows  = $group.find('tbody tr');
        $rows.find('input[name="products[]"]').prop('checked', true);
        $rows.last().find('input[name="products[]"]').prop('checked', false);
        $group.find('.cb-select-all').prop('checked', false);
    });

    // -------------------------------------------------------------------------
    // Form submission — validation and contextual confirmation messages
    // -------------------------------------------------------------------------

    $form.on('submit', function(e) {
        var selectedProducts = $('input[name="products[]"]:checked').length;
        var productAction    = $('select[name="product_action"]').val();
        var imageAction      = $('select[name="image_action"]').val();

        if (selectedProducts === 0) {
            alert(cptsm2_vars.no_products_selected);
            e.preventDefault();
            return false;
        }

        if (!productAction && !imageAction) {
            alert(cptsm2_vars.select_action);
            e.preventDefault();
            return false;
        }

        var confirmMsg = '';

        if (productAction === 'delete' || imageAction === 'remove_featured' || imageAction === 'remove_gallery' || imageAction === 'remove_all_images') {
            confirmMsg = cptsm2_vars.confirm_delete;
            if (imageAction) {
                confirmMsg = cptsm2_vars.confirm_images;
            }
        } else if (productAction === 'trash') {
            confirmMsg = cptsm2_vars.confirm_trash;
        } else if (productAction === 'draft') {
            confirmMsg = cptsm2_vars.confirm_draft;
        }

        if (confirmMsg && !confirm(confirmMsg)) {
            e.preventDefault();
            return false;
        }
    });

    // -------------------------------------------------------------------------
    // 301 Redirects page — "Select All" for the redirects table
    // -------------------------------------------------------------------------
    $('#cb-select-all').on('change', function() {
        $('input[name="delete_redirect[]"]').prop('checked', $(this).prop('checked'));
    });
});
