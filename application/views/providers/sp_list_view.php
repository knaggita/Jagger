<?php
$form = '<form id="filter-form">'. lang('rr_filter') .': <input name="filter" id="filter" value="" maxlength="30" size="30" type="text"></form>';
/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */
echo $form;
$tmpl = array('table_open' => '<table  id="details" class="zebra splist">');

$this->table->set_template($tmpl);
$this->table->set_heading(lang('tbl_title_nameandentityid'),'#',lang('tbl_title_regdate'), lang('tbl_title_helpurl'));
$this->table->set_caption(lang('rr_tbltitle_listsps').' ('.lang('rr_found').' '.$sps_count.')');
echo $this->table->generate($sprows);
$this->table->clear();
?>
<script type="text/javascript" src="<?php echo base_url(); ?>js/jquery.uitablefilter.js"></script>
<script>
    $(function(){
           
        $('table.splist tr td:first-child span.alert').removeClass('alert').parent().addClass('alert');
        var theTable = $('table.splist')
        theTable.find("tbody > tr").find("td:eq(1)").mousedown(function(){
        });
        $("#filter").keyup(function() {
            $.uiTableFilter( theTable, this.value );
        })
        $('#filter-form').submit(function(){
            theTable.find("tbody > tr:visible > td:eq(1)").mousedown();
            return false;
        }).focus(); 
    });  
</script>
