jQuery(function($){
    let jobId = null, total = 0;

    function readCSV(file, cb) {
        const reader = new FileReader();
        reader.onload = e => {
            const lines = e.target.result.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
            const urls = lines.map(l => l.replace(/^\"|\"$/g,'')).filter(l => /^https?:\/\//i.test(l));
            cb(urls);
        };
        reader.readAsText(file);
    }

    $('#lre_start').on('click', function(){
        const fileInput = $('#lre_csv')[0];
        const pattern = $('#lre_pattern').val().trim();
        const delay = parseInt($('#lre_delay').val()) || 1500;

        if(!fileInput.files.length){ alert('Please upload CSV'); return; }

        readCSV(fileInput.files[0], urls => {
            $.post(wp_lre.ajax_url, {
                action: 'wp_lre_start',
                nonce: wp_lre.nonce,
                urls: JSON.stringify(urls),
                pattern, delay
            }, res => {
                if(!res.success) return alert(res.data);
                jobId = res.data.job_id; total = res.data.total;
                $('#lre_progress_wrap').show();
                $('#lre_progress_text').text(`Starting 0 / ${total}`);
                processNext(delay);
            });
        });
    });

    function processNext(delay){
        $.post(wp_lre.ajax_url, {
            action: 'wp_lre_next',
            nonce: wp_lre.nonce,
            job_id: jobId
        }, res => {
            if(!res.success) return alert(res.data);
            const d = res.data;
            $('#lre_progress_bar').css('width', d.progress + '%');
            $('#lre_progress_text').text(`${d.completed} / ${d.total} completed (${d.progress}%)`);
            const r = d.result;
            const colorClass = r.found.includes('Found') ? 'found' : (r.found.includes('Error') ? 'error' : 'not-found');
            const row = $(`<div class="lre-result ${colorClass}">
                <strong>${r.url}</strong> → ${r.found} (${r.status})
            </div>`);
            $('#lre_results').prepend(row);
            if(!d.done) setTimeout(()=>processNext(delay), delay);
            else {
                $('#lre_progress_text').text(`✅ Completed ${d.total} URLs`);
                $('#lre_download').prop('disabled', false).off().on('click', ()=> {
                    window.location = `${wp_lre.ajax_url}?action=wp_lre_download&job_id=${jobId}&nonce=${wp_lre.nonce}`;
                });
            }
        });
    }
});
