{{-- Kuyruğa alınmış yazmanın sonucu burada beklenir; adres sunucudan gelir. --}}
<div class="toast-host"
     data-flash="{{ session('status') ?? session('error') ?? '' }}"
     data-flash-variant="{{ session('error') ? 'danger' : 'success' }}"
     @if (session('write_job_url')) data-write-job-url="{{ session('write_job_url') }}" @endif></div>
