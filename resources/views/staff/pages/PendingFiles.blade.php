@extends('staff.dashboard.staffDashboard')

@section('content')

<meta name="csrf-token" content="{{ csrf_token() }}">
<style>
    td {
        text-align: center;
    }
    .status-pending {
        background-color: red;
        color: white;
        padding: 4px 8px;
        -radius: 4px;
        display: inline-block;
    }
    .file-icon {
        margin-right: 5px;
    }
</style>

<!-- Include Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

<div class="container mx-auto p-6 bg-white rounded-xl" style="box-shadow: 4px 4px 10px rgba(0, 0, 0, 0.1);">

    <h1 class="text-2xl font-bold mb-4">Pending File Requests</h1>
    

    <!-- Search Bar -->
    <input type="text" id="searchInput" class="border w-full p-2 mb-4  -gray-300 rounded" 
           placeholder="Search requests...">

    <div class="mt-2 flex items-center text-red-600 text-sm mb-2">
        <i class="fas fa-info-circle mr-2"></i>
        Pending files will be approved only by the <span class="font-semibold ml-1"> CSIT Administrator</span>.
    </div>
    
    <table class="w-full -collapse  -gray-300">
        <thead>
            <tr class="bg-gray-200">
                <th class=" p-2">Request ID</th>
                <th class=" p-2">File Name</th>
                <th class=" p-2">Requested By</th>
                <th class=" p-2">Request Status</th>
                <th class=" p-2">Requested At</th>
            </tr>
        </thead>
        <tbody id="requestTable">
            @forelse($fileRequests as $request)
                @php
                    $filename = $request->file->filename ?? 'Unknown';
                    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

                    $iconClass = match ($extension) {
                        'doc', 'docx' => 'fa-file-word text-blue-600',
                        'pdf' => 'fa-file-pdf text-red-600',
                        'xls', 'xlsx' => 'fa-file-excel text-green-600',
                        'ppt', 'pptx' => 'fa-file-powerpoint text-orange-600',
                        'zip', 'rar' => 'fa-file-zipper text-yellow-600',
                        default => 'fa-file text-gray-600',
                    };
                @endphp
                <tr>
                    <td class=" p-2">REQ00{{ $request->request_id }}</td>
                    <td class=" p-2">
                        <i class="fa-solid {{ $iconClass }}"></i> {{ $filename }}
                    </td>
                    <td class=" p-2">{{ $request->user->name ?? 'Unknown' }}</td>
                    <td class=" p-2">{{ $request->created_at->diffForHumans() }}</td>
                    <td class=" p-2">
                        <span class="status-pending">{{ ucfirst($request->request_status) }}</span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class=" p-2 text-center text-gray-500">No pending requests found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

</div>

<script>
    document.getElementById('searchInput').addEventListener('keyup', function () {
        let filter = this.value.toLowerCase();
        let rows = document.querySelectorAll("#requestTable tr");

        rows.forEach(row => {
            let text = row.innerText.toLowerCase();
            row.style.display = text.includes(filter) ? "" : "none";
        });
    });
</script>

@endsection
