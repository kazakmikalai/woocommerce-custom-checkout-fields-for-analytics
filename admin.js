(function() {
    console.log('BYGI DEBUG: admin.js loaded');
    
    const { addFilter } = window.wp.hooks;

    function bygiAddCustomFieldsToOrdersReport(reportTableData) {
        console.log('BYGI DEBUG: bygiAddCustomFieldsToOrdersReport called');
        console.log('BYGI DEBUG: reportTableData =', reportTableData);
        
        if (reportTableData.endpoint !== 'orders') {
            console.log('BYGI DEBUG: Not orders endpoint, returning original data');
            return reportTableData;
        }

        console.log('BYGI DEBUG: Processing orders endpoint');
        console.log('BYGI DEBUG: items.data =', reportTableData.items.data);

        // Проверяем, существуют ли уже наши колонки
        const hasFullNameColumn = reportTableData.headers.some(header => header.key === 'custom_full_name');
        const hasRoomNumberColumn = reportTableData.headers.some(header => header.key === 'custom_room_number');

        if (!hasFullNameColumn) {
            console.log('BYGI DEBUG: Adding custom columns to headers');
            reportTableData.headers = [
                ...reportTableData.headers,
                { label: 'ФИО', key: 'custom_full_name' },
                { label: 'Номер комнаты', key: 'custom_room_number' },
            ];
        }

        console.log('BYGI DEBUG: Processing rows, rows count =', reportTableData.rows.length);
        reportTableData.rows = reportTableData.rows.map((row, index) => {
            const item = reportTableData.items.data[index] || {};
            console.log(`BYGI DEBUG: Processing row ${index}, item =`, item);
            
            const fullName = item.custom_full_name || '';
            const roomNumber = item.custom_room_number || '';
            
            console.log(`BYGI DEBUG: Row ${index} - full_name: "${fullName}", room_number: "${roomNumber}"`);
            
            return [
                ...row,
                { display: fullName, value: fullName },
                { display: roomNumber, value: roomNumber },
            ];
        });

        console.log('BYGI DEBUG: Final reportTableData =', reportTableData);
        return reportTableData;
    }

    addFilter(
        'woocommerce_admin_report_table',
        'bygi-custom-checkout-fields',
        bygiAddCustomFieldsToOrdersReport
    );
    
    console.log('BYGI DEBUG: Filter added');
})();