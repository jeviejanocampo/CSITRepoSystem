<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\FileVersions;
use App\Models\Files;
use App\Models\AccessLog;
use App\Models\FileTimeStamp;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\FileRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;


class FileController extends Controller
{

    public function downloadFile($filePath)
    {
        // Ensure that the file path doesn't start with 'uploads/' (because it could break the path)
        $storagePath = 'uploads/' . $filePath;  // Full path to the file inside 'uploads'
    
        // Check if the file exists in the 'uploads' folder
        if (Storage::disk('public')->exists($storagePath)) {
            // Generate the correct path to be used for download
            return response()->download(storage_path("app/public/$storagePath"));
        }
    
        // Check if the file exists in 'uploads/primaryFiles' folder
        $primaryFilePath = 'uploads/primaryFiles/' . $filePath;
        if (Storage::disk('public')->exists($primaryFilePath)) {
            return response()->download(storage_path("app/public/$primaryFilePath"));
        }
    
        // If not found, return an error
        return back()->with('error', 'File not found.');
    }
    
    public function AdminshowFolders(Request $request)
    {
        $basePath = $request->get('path', 'uploads'); // Default to 'uploads'
    
        $directories = Storage::disk('public')->directories($basePath);
    
        $folderNames = array_map(function ($dir) use ($basePath) {
            return Str::after($dir, $basePath . '/');
        }, $directories);
    
        // Determine parent path for "Back" navigation
        $parentPath = dirname($basePath);
        if ($parentPath === '.' || $basePath === '') {
            $parentPath = null;
        }
    
        return view('admin.pages.AdminFolders', compact('folderNames', 'basePath', 'parentPath'));
    }

    public function AdminuploadFile(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:502400', 
            'category' => 'required|in:capstone,thesis,faculty_request,accreditation,admin_docs',
            'published_by' => 'required|string|max:255',
            'year_published' => 'required|string|regex:/^\d{4}$/', // ✅ Ensure it’s a 4-digit year
            'description' => 'nullable|string|max:1000',
            'folder' => 'nullable|string|max:255', 
        ]);

        if (!session()->has('user')) {
            return response()->json(['message' => 'Unauthorized: Please log in.'], 403);
        }

        $user = session('user');

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $filename = $file->getClientOriginalName();

            $folder = trim($request->input('folder'));
            $uploadPath = 'uploads' . ($folder ? '/' . $folder : '');

            $filePath = $file->storeAs($uploadPath, $filename, 'public');

            $fileEntry = Files::create([
                'filename' => $filename,
                'file_path' => $filePath,
                'file_size' => $file->getSize(),
                'file_type' => $file->getClientOriginalExtension(),
                'uploaded_by' => $user->id,
                'category' => $request->category,
                'published_by' => $request->published_by,
                'year_published' => (string) $request->year_published, // ✅ Ensure it's stored as a string
                'description' => $request->description ?? null,
                'status' => 'active', // ✅ Directly set to active
            ]);

            if ($fileEntry) {
                AccessLog::create([
                    'file_id' => $fileEntry->id ?? 0,
                    'accessed_by' => $user->id,
                    'action' => 'Uploaded file - Successful', // ✅ Modified action log
                    'access_time' => now(),
                ]);

                return response()->json(['message' => 'File uploaded successfully and marked as active!'], 200);
            }

            return response()->json(['message' => 'File upload failed.'], 500);
        }

        return response()->json(['message' => 'No file detected.'], 400);
    }

    public function AdmindeleteFolder(Request $request)
    {
        $request->validate([
            'folderName' => 'required|string',
            'basePath' => 'required|string',
        ]);
    
        $fullPath = $request->basePath . '/' . $request->folderName;
    
        if (!Storage::disk('public')->exists($fullPath)) {
            Log::warning("Attempted to delete non-existent folder: {$fullPath} by user ID " . Auth::id());
    
            return response()->json(['success' => false, 'message' => 'Folder does not exist.']);
        }
    
        try {
            Storage::disk('public')->deleteDirectory($fullPath);
    
            $user = session('user');
    
            // Access log database entry
            AccessLog::create([
                'file_id' => 0, // No file ID since it's a folder
                'accessed_by' => $user->id,
                'action' => "Deleted subfolder '{$request->folderName}' under '{$request->basePath}' - Successful",
                'access_time' => now(),
            ]);
    
            // Laravel log
            Log::info("User ID {$user->id} successfully deleted folder: {$fullPath}");
    
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error("Failed to delete folder: {$fullPath} - Error: " . $e->getMessage());
    
            return response()->json(['success' => false, 'message' => 'Failed to delete folder.']);
        }
    }

    public function AdmincreateFolder(Request $request)
    {
        $request->validate([
            'folderName' => 'required|string',
            'basePath' => 'nullable|string'
        ]);
    
        $basePath = $request->input('basePath', 'uploads');
        $folderName = $request->input('folderName');
        $newPath = $basePath . '/' . $folderName;
    
        if (Storage::disk('public')->exists($newPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Folder already exists.'
            ]);
        }
    
        try {
            // Create the new folder
            Storage::disk('public')->makeDirectory($newPath);
    
            // Access log entry for folder creation
            $user = session('user'); // Retrieve the user from session
    
            AccessLog::create([
                'file_id' => 0, // No file ID since it's a folder
                'accessed_by' => $user->id, // Use session user's ID
                'action' => "Created folder '{$folderName}' under '{$basePath}' - Successful",
                'access_time' => now(),
            ]);
    
            // Log the action for auditing
            Log::info("User ID {$user->id} successfully created folder: {$newPath}");
    
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error("Failed to create folder: {$newPath} - Error: " . $e->getMessage());
    
            return response()->json([
                'success' => false,
                'message' => 'Failed to create folder: ' . $e->getMessage()
            ]);
        }
    }
    

    public function AdminactiveFiles(Request $request)
    {
        $files = Files::query();
    
        if ($request->has('search') && !empty($request->search)) {
            $files->where('filename', 'LIKE', '%' . $request->search . '%');
        }
    
        if ($request->has('file_type') && !empty($request->file_type)) {
            $files->where('file_type', $request->file_type);
        }
    
        if ($request->has('subfolder') && !empty($request->subfolder)) {
            $files->where('file_path', 'LIKE', 'uploads/' . $request->subfolder . '/%');
        }
    
        $files = $files->paginate(20)->appends(['subfolder' => $request->subfolder]);
    
        $fileVersions = FileVersions::whereIn('file_id', $files->pluck('file_id'))->get();
    
        // 🔥 Get subfolders from uploads directory
        $uploadPath = public_path('storage/uploads');
        $subfolders = [];
    
        if (File::exists($uploadPath)) {
            $subfolders = collect(File::directories($uploadPath))->map(function ($path) {
                return basename($path); // Just get the folder name
            });
        }
    
        return view('admin.pages.AdminViewAllFilesActive', compact('fileVersions', 'files', 'subfolders'));
    }

    public function AdmindownloadFile($filePath)
    {
        $storagePath = 'uploads/' . $filePath;
    
        if (Storage::disk('public')->exists($storagePath)) {
            return response()->download(storage_path("app/public/$storagePath"));
        }
    
        $primaryFilePath = 'uploads/primaryFiles/' . $filePath;
        if (Storage::disk('public')->exists($primaryFilePath)) {
            return response()->download(storage_path("app/public/$primaryFilePath"));
        }
    
        $subfolders = ['capstone', 'files'];
    
        foreach ($subfolders as $subfolder) {
            $subfolderPath = 'uploads/' . $subfolder . '/' . $filePath;
    
            if (Storage::disk('public')->exists($subfolderPath)) {
                return response()->download(storage_path("app/public/$subfolderPath"));
            }
        }
    
        return back()->with('error', 'File not found.');
    }    

    public function AdmineditPrimaryFile($file_id)
    {
        // Fetch the file using the provided ID
        $file = Files::findOrFail($file_id);

        return view('admin.pages.AdminEditPrimaryFile', compact('file'));
    }

    public function AdminActiveFileArchived($file_id)
    {
        // Find the file
        $file = Files::find($file_id);
    
        if (!$file) {
            return redirect()->back()->with('error', 'File not found');
        }
    
        // Update the status to archived
        $file->status = 'archived';
        $file->save();
    
        // Insert into file_time_stamps to log the event
        FileTimeStamp::create([
            'file_id' => $file->file_id,
            'event_type' => 'File ID ' . $file->id . ' Archived', // Log archive event
            'timestamp' => now(),
        ]);
    
        return redirect()->back()->with('success', 'File successfully archived');
    }
    
    public function AdminupdatePrimaryFile(Request $request, $file_id)
    {
        $file = Files::findOrFail($file_id);
    
        // Validate input
        $request->validate([
            'filename' => 'required|string|max:255',
            'category' => 'required|string|max:50',
            'year_published' => 'nullable|integer|min:1900|max:' . date('Y'),
            'published_by' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|string|in:active,inactive,pending,deactivated',
            'file' => 'nullable|file|max:5120', // Optional file upload, max 5MB
        ]);
    
        // Check if a new file is uploaded
        if ($request->hasFile('file')) {
            $uploadedFile = $request->file('file');
            $newFileName = $uploadedFile->getClientOriginalName();
            $filePath = $uploadedFile->storeAs('uploads/primaryFiles', $newFileName, 'public');
    
            // Delete old file if it exists
            if ($file->file_path) {
                Storage::disk('public')->delete($file->file_path);
            }
    
            $file->file_path = $filePath;
            $file->file_size = $uploadedFile->getSize();
        } else {
            // Rename the existing file
            $oldFilePath = $file->file_path;
    
            if ($oldFilePath && str_starts_with($oldFilePath, 'uploads/')) {
                $directory = dirname($oldFilePath);
                $oldExtension = pathinfo($oldFilePath, PATHINFO_EXTENSION);
                $newFileName = pathinfo($request->filename, PATHINFO_FILENAME) . '.' . $oldExtension;
                $newFilePath = $directory . '/' . $newFileName;
    
                Storage::disk('public')->move($oldFilePath, $newFilePath);
                $file->file_path = $newFilePath;
            }
        }
    
        // Update file details
        $file->filename = pathinfo($request->filename, PATHINFO_FILENAME);
        $file->category = $request->category;
        $file->year_published = $request->year_published;
        $file->published_by = $request->published_by;
        $file->description = $request->description;
        $file->status = $request->status;
        $file->save();
    
        return redirect()->route('admin.active.files', $file_id)->with('success', 'File updated successfully!');
    }

    public function TrashActiveFile(Request $request, $file_id)
    {
        $file = Files::find($file_id);

        if (!$file) {
            return redirect()->back()->with('error', 'File not found.');
        }

        try {
            $file->status = 'deleted';
            $file->save();

            return redirect()->back()->with('success', 'File successfully marked as trashed.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'An error occurred while deleting the file.');
        }
    }


    public function AdminArchivedViewFilesVersions(Request $request) 
    {
        // Fetch all archived file versions
        $fileVersionsQuery = FileVersions::where('status', 'archived');
    
        // Fetch all archived files
        $filesQuery = Files::where('status', 'archived');
    
        // Apply search filter
        if ($request->has('search') && !empty($request->search)) {
            $fileVersionsQuery->where('filename', 'LIKE', '%' . $request->search . '%');
            $filesQuery->where('filename', 'LIKE', '%' . $request->search . '%');
        }
    
        // Apply file type filter
        if ($request->has('file_type') && !empty($request->file_type)) {
            $fileVersionsQuery->where('file_type', $request->file_type);
            $filesQuery->where('file_type', $request->file_type);
        }
    
        // Apply category filter
        if ($request->has('category') && !empty($request->category)) {
            $fileVersionsQuery->where('category', $request->category);
            $filesQuery->where('category', $request->category);
        }
    
        // Merge results and paginate
        $archivedFiles = $filesQuery->get();
        $archivedFileVersions = $fileVersionsQuery->get();
        $mergedResults = $archivedFiles->merge($archivedFileVersions)->sortByDesc('updated_at');
    
        // Paginate manually
        $perPage = 6;
        $currentPage = request()->input('page', 1);
        $paginatedResults = $mergedResults->slice(($currentPage - 1) * $perPage, $perPage)->values();
        $fileVersions = new \Illuminate\Pagination\LengthAwarePaginator($paginatedResults, $mergedResults->count(), $perPage, $currentPage, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    
        return view('admin.pages.adminArchivedFiles', compact('fileVersions'));
    }

    public function AdminunarchiveFile($id)
    {
        // Check if the ID exists in file_versions first
        $fileVersion = FileVersions::where('version_id', $id)->first();
    
        if ($fileVersion) {
            // Update status in file_versions
            $fileVersion->update(['status' => 'active']);
    
            // Log unarchive event
            FileTimeStamp::create([
                'file_id' => $fileVersion->file_id,
                'version_id' => $fileVersion->version_id,
                'event_type' => 'File Version ID ' . $fileVersion->version_id . ' Unarchived',
                'timestamp' => now(),
            ]);
    
            return redirect()->back()->with('success', 'File version unarchived successfully!');
        }
    
        // If not found in file_versions, check in files (for original files)
        $originalFile = Files::where('file_id', $id)->first() ?? 0;
    
        if ($originalFile) {
            // Update status in files (original file)
            $originalFile->update(['status' => 'active']);
    
            // Log unarchive event
            FileTimeStamp::create([
                'file_id' => $originalFile->file_id,
                'version_id' => null,
                'event_type' => 'File ID ' . $originalFile->id . ' Unarchived',
                'timestamp' => now(),
            ]);
    
            return redirect()->back()->with('success', 'Original file unarchived successfully!');
        }
    
        return redirect()->back()->with('error', 'File not found!');
    }
    
    public function AdminCountActiveFiles(Request $request)
    {
        // ✅ Count all active files
        $activeFilesCount = Files::where('status', 'active')->count();
        
        // ✅ Count all pending files
        $pendingFilesCount = Files::where('status', 'pending')->count();
    
        // ✅ Get filter type from request, default to 'all' (count all uploads)
        $filter = $request->get('filter', 'all'); 
    
        // ✅ Apply the filter for recent uploads count
        switch ($filter) {
            case 'daily':
                $recentUploadsCount = Files::whereDate('created_at', today())->count();
                break;
            case 'monthly':
                $recentUploadsCount = Files::whereMonth('created_at', now()->month)->count();
                break;
            case 'yearly':
                $recentUploadsCount = Files::whereYear('created_at', now()->year)->count();
                break;
            default: // 'all' or no filter
                $recentUploadsCount = Files::count();  // Count all uploads
                break;
        }
    
        // ✅ Get total storage used
        $uploadPath = storage_path('app/public/uploads'); // Absolute path
        $totalStorageUsed = $this->getFolderSize($uploadPath); // Get folder size
        $formattedStorage = $this->formatSizeUnits($totalStorageUsed); // Format size
    
        // ✅ Fetch recent file activities (latest updated files)
        $recentFiles = Files::orderBy('updated_at', 'desc')->limit(10)->get();
    
        // ✅ Return all necessary data to the view
        return view('admin.pages.adminDashboardPage', compact(
            'activeFilesCount', 
            'pendingFilesCount', 
            'recentUploadsCount', 
            'formattedStorage',
            'recentFiles',
            'filter' // Pass the filter to the view
        ));
    }

    private function getFolderSize($folder)
    {
        $size = 0;
        foreach (glob(rtrim($folder, '/') . '/*', GLOB_NOSORT) as $file) {
            $size += is_file($file) ? filesize($file) : $this->getFolderSize($file);
        }
        return $size;
    }

    private function formatSizeUnits($bytes)
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' Bytes';
        }
    }

    public function AdminTrashViewFilesVersions(Request $request) 
    {
        $query = Files::where('status', 'deleted'); // Only get deleted files
    
        // Apply search filter
        if ($request->has('search') && !empty($request->search)) {
            $query->where('filename', 'LIKE', '%' . $request->search . '%');
        }
    
        // Apply file type filter
        if ($request->has('file_type') && !empty($request->file_type)) {
            $query->where('file_type', $request->file_type);
        }
    
        // Apply category filter
        if ($request->has('category') && !empty($request->category)) {
            $query->where('category', $request->category);
        }
    
        // Fetch filtered results
        $fileVersions = $query->with('user')->paginate(10); // Include user relationship
    
        return view('admin.pages.AdminTrashBinFiles', compact('fileVersions'));
    }
    

    public function AdminRestoreFile($file_id)
    {
        // Find the file from the files table
        $file = Files::findOrFail($file_id);
    
        // Update status to 'active'
        $file->update(['status' => 'active']);
    
        // Log event to file_time_stamps
        FileTimeStamp::create([
            'file_id' => $file->file_id,
            'version_id' => null, // or remove this if version_id is nullable
            'event_type' => 'File ID ' . $file->file_id . ' Restored from Trash',
            'timestamp' => now(),
        ]);
    
        return redirect()->back()->with('success', 'File restored successfully!');
    }
    

    public function downloadFileUpdated($filename)
    {
        $filePath = 'uploads/files/' . $filename; 
    
        if (Storage::disk('public')->exists($filePath)) {
            return Storage::disk('public')->download($filePath);
        }
    
        return back()->with('error', 'File not found.');
    }

    public function editFileVersion($version_id)
    {
        $fileVersion = FileVersions::where('version_id', $version_id)->firstOrFail(); // Fetch file version by version_id
    
        return view('admin.pages.EditFileVersion', compact('fileVersion'));
    }


    public function updateFileVersion(Request $request, $version_id)
    {
        // Fetch file version by version_id
        $fileVersion = FileVersions::where('version_id', $version_id)->firstOrFail();
    
        // Validate input
        $request->validate([
            'filename' => 'required|string|max:255',
            'file_type' => 'required|string|max:10',
            'file' => 'nullable|file|max:5120', // Optional file upload, max 5MB
        ]);
    
        // Handle file upload
        if ($request->hasFile('file')) {
            $uploadedFile = $request->file('file');
            $newFileName = $uploadedFile->getClientOriginalName();
            $filePath = $uploadedFile->storeAs('uploads/files', $newFileName, 'public'); // Store with new name
    
            // Update file details
            $fileVersion->file_path = 'uploads/files/' . $newFileName;
            $fileVersion->file_size = $uploadedFile->getSize();
            $fileVersion->file_type = $uploadedFile->getClientOriginalExtension();
            $fileVersion->updated_at = now();
            $fileVersion->save();

        }
    
        // Update other details
        $fileVersion->filename = $request->filename;
        $fileVersion->save();
    
        return redirect()->route('admin.update')->with('success', 'File version updated successfully!');
    }


    public function archiveFile($version_id)
    {
        // Find the file version
        $fileVersion = FileVersions::findOrFail($version_id);

        // Update status to 'archived'
        $fileVersion->update(['status' => 'archived']);

        return redirect()->back()->with('success', 'File version unarchived successfully!');
    }

    public function RestoreFile($version_id)
    {
        // Find the file version
        $fileVersion = FileVersions::findOrFail($version_id);

        // Update status to 'archived'
        $fileVersion->update(['status' => 'active']);

        return redirect()->back()->with('success', 'File version restored successfully!');
    }

    public function unarchiveFile($version_id)
    {
        // Find the file version
        $fileVersion = FileVersions::findOrFail($version_id);

        // Update status to 'archived'
        $fileVersion->update(['status' => 'active']);

        return redirect()->back()->with('success', 'File version archived successfully!');
    }

    public function moveToTrash(Request $request, $id)
    {
        // Find the file by file_id
        $file = Files::where('file_id', $id)->first();
    
        if ($file) {
            $file->status = 'deleted';
            $file->save();
            return redirect()->back()->with('success', 'File moved to trash successfully.');
        }
    
        return redirect()->back()->with('error', 'File not found.');
    }
    
    



    public function TrashFile($version_id)
    {
        // Find the file version
        $fileVersion = FileVersions::findOrFail($version_id);

        // Update status to 'archived'
        $fileVersion->update(['status' => 'deleted']);

        return redirect()->back()->with('success', 'File version placed on trash successfully!');
    }

    public function OverviewTrashFile($version_id)
    {
        // Find the file version
        $fileVersion = FileVersions::findOrFail($version_id);

        // Update status to 'archived'
        $fileVersion->update(['status' => 'deleted']);

        return redirect()->back()->with('success', 'File version placed on trash successfully!');
    }


    public function archiveFileAdmin($file_id)
    {
        if (!session()->has('user')) {
            return redirect()->route('admin.upload')->with('error', 'Unauthorized: Please log in.');
        }

        $file = Files::findOrFail($file_id);

        if ($file->status === 'archived') {
            return redirect()->back()->with('error', 'This file is already archived.');
        }

        $user = session('user');
        if (!$user || !$user->isAdmin()) { 
            return redirect()->back()->with('error', 'Unauthorized: You do not have permission.');
        }

        $file->update(['status' => 'archived']);

        return redirect()->back()->with('success', 'File archived successfully!');
    }

    public function editPrimaryFile($file_id)
    {
        // Fetch the file using the provided ID
        $file = Files::findOrFail($file_id);

        return view('admin.pages.EditPrimaryFile', compact('file'));
    }


    public function updatePrimaryFile(Request $request, $file_id)
    {
        $file = Files::findOrFail($file_id);
    
        // Validate input
        $request->validate([
            'filename' => 'required|string|max:255',
            'category' => 'required|string|max:50',
            'status' => 'required|string|in:active,inactive,pending,deactivated',
            'file' => 'nullable|file|max:5120', // Optional file upload, max 5MB
        ]);
    
        // Check if a new file is uploaded
        if ($request->hasFile('file')) {
            $uploadedFile = $request->file('file');
    
            // Use the original filename
            $newFileName = $uploadedFile->getClientOriginalName();
            
            // Store the new file in 'uploads/primaryFiles' directory
            $filePath = $uploadedFile->storeAs('uploads/primaryFiles', $newFileName, 'public');
    
            // Delete old file if it exists
            if ($file->file_path) {
                Storage::disk('public')->delete($file->file_path);
            }
    
            // Update file path & size
            $file->file_path = $filePath;
            $file->file_size = $uploadedFile->getSize();
        } else {
            // If no new file is uploaded, rename the existing file
            $oldFilePath = $file->file_path; // Get the existing file path
    
            if ($oldFilePath && str_starts_with($oldFilePath, 'uploads/')) {
                // Extract the directory and get the file extension
                $directory = dirname($oldFilePath);
                $oldExtension = pathinfo($oldFilePath, PATHINFO_EXTENSION);
                
                // Ensure the filename doesn't already contain the extension
                $newFileName = pathinfo($request->filename, PATHINFO_FILENAME) . '.' . $oldExtension;
                $newFilePath = $directory . '/' . $newFileName;
    
                // Rename the file in storage
                Storage::disk('public')->move($oldFilePath, $newFilePath);
    
                // Update the file path in the database
                $file->file_path = $newFilePath;
            }
        }
    
        // Update file details
        $file->filename = pathinfo($request->filename, PATHINFO_FILENAME); // Save filename without extension
        $file->category = $request->category;
        $file->status = $request->status;
        $file->save();
    
        return redirect()->route('admin.files', $file_id)->with('success', 'File updated successfully!');
    }
    







    

    


   


    
    
}
