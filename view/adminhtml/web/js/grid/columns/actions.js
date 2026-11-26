define([
    'jquery',
    'Magento_Ui/js/grid/columns/actions',
    'Meetanshi_SMTP/js/grid/columns/dompurify',
    'Magento_Ui/js/modal/modal'
], function ($, Column, DOMPurify) {
    'use strict';

    return Column.extend({
        modal: {},

        defaultCallback: function (actionIndex, recordId, action) {
            if (actionIndex !== 'view') {
                return this._super();
            }

            if (typeof this.modal[action.rowIndex] === 'undefined') {
                var row = this.rows[action.rowIndex],
                    // content is stored HTML-escaped: decode it (textarea never runs markup), then sanitize
                    decodedContent = $('<textarea>').html(row['email_content'] || '').text(),
                    sanitizedContent = DOMPurify.sanitize(decodedContent),
                    modalHtml = '<div>' + sanitizedContent + '</div>';
                this.modal[action.rowIndex] = $('<div>')
                    .html(modalHtml)
                    .modal({
                        type: 'slide',
                        title: $('<div>').text(row['subject'] || '').html(),
                        innerScroll: true,
                        buttons: []
                    });
            }

            this.modal[action.rowIndex].trigger('openModal');
        }
    });
});