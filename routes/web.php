<?php

use App\Http\Controllers\AdminEmailAttachmentController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\AuthSecurityController;
use App\Http\Controllers\AvatarModelController;
use App\Http\Controllers\CaseManagerDashboardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\FileManagerController;
use App\Http\Controllers\InvestigationCaseController;
use App\Http\Controllers\ManualUploadController;
use App\Http\Controllers\PersonController;
use App\Http\Controllers\ProfileManagerDashboardController;
use App\Http\Controllers\ReportMediaController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffProfilePhotoController;
use App\Http\Controllers\UploadTransferController;
use App\Http\Controllers\VaultShellController;
use App\Http\Controllers\VideoUploadController;
use App\Livewire\Admin\ActivityLogIndex;
use App\Livewire\Admin\AuthHistory;
use App\Livewire\Admin\AuthSecurityDashboard;
use App\Livewire\Admin\BlacklistedIpIndex;
use App\Livewire\Admin\BruteForceIndex;
use App\Livewire\Admin\DepartmentManagement;
use App\Livewire\Admin\DepartmentStaffDirectory;
use App\Livewire\Admin\MailCenter;
use App\Livewire\Admin\ReportManagement;
use App\Livewire\Admin\StaffManagement;
use App\Livewire\Admin\StaffProfile;
use App\Livewire\Admin\TwoFactorSettings;
use App\Livewire\Admin\WhitelistedIpIndex;
use App\Livewire\ReportWorkspace;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (Auth::guard('admin')->check()) {
        return redirect('/admin');
    }

    $staff = Auth::guard('web')->user();
    if ($staff !== null) {
        return redirect($staff->department !== null ? '/'.$staff->department : '/admin');
    }

    return view('landing.index');
})->name('home');

Route::middleware('guest:admin')->group(function () {
    Route::get('/admin/login', [LoginController::class, 'create'])->name('login');
    Route::post('/admin/login', [LoginController::class, 'store'])
        ->name('auth.attempt');
});

$departments = [
    'cfm' => 'Case File Management',
    'pm' => 'Profile Management',
    'fm' => 'File Management',
];

foreach ($departments as $department => $departmentName) {
    Route::middleware('guest:web')->get('/'.$department.'/login', fn () => view('auth.department-login', [
        'department' => $department,
        'departmentName' => $departmentName,
    ]))->name($department.'.login');

    Route::middleware(['auth:web', 'department:'.$department, 'security.presence'])->prefix($department)->name($department.'.')->group(function () use ($department, $departmentName): void {
        if ($department === 'cfm') {
            Route::get('/', [CaseManagerDashboardController::class, 'index'])->name('dashboard');
            Route::get('/cases', [InvestigationCaseController::class, 'index'])->name('cases.index');
            Route::get('/cases/create', [InvestigationCaseController::class, 'create'])->name('cases.create');
            Route::post('/cases', [InvestigationCaseController::class, 'store'])->name('cases.store');
            Route::get('/cases/{caseFile}', [InvestigationCaseController::class, 'show'])->name('cases.show');
            Route::get('/cases/{caseFile}/edit', [InvestigationCaseController::class, 'edit'])->name('cases.edit');
            Route::put('/cases/{caseFile}', [InvestigationCaseController::class, 'update'])->name('cases.update');
            Route::get('/cases/{caseFile}/evidence/{file:uuid}/preview', [InvestigationCaseController::class, 'previewEvidence'])->withoutScopedBindings()->name('cases.evidence-preview');
            Route::get('/cases/{caseFile}/evidence/{file:uuid}/stream', [InvestigationCaseController::class, 'streamEvidence'])->withoutScopedBindings()->name('cases.evidence-stream');
            Route::get('/cases/{caseFile}/images/{image}', [InvestigationCaseController::class, 'image'])->name('cases.image');
            Route::get('/cases/{caseFile}/images/{image}/download', [InvestigationCaseController::class, 'image'])->defaults('download', true)->name('cases.images.download');
            Route::get('/cases/{caseFile}/images/{image}/poster', [InvestigationCaseController::class, 'imagePoster'])->name('cases.images.poster');
            Route::delete('/cases/{caseFile}/images/{image}', [InvestigationCaseController::class, 'destroyImage'])->name('cases.images.destroy');
        } elseif ($department === 'pm') {
            Route::get('/', [ProfileManagerDashboardController::class, 'index'])->name('dashboard');
            Route::get('/people', [PersonController::class, 'index'])->name('people.index');
            Route::get('/people/create', [PersonController::class, 'create'])->name('people.create');
            Route::post('/people', [PersonController::class, 'store'])->name('people.store');
            Route::get('/people/{person}', [PersonController::class, 'show'])->name('people.show');
            Route::get('/people/{person}/edit', [PersonController::class, 'edit'])->name('people.edit');
            Route::put('/people/{person}', [PersonController::class, 'update'])->name('people.update');
            Route::get('/people/{person}/images/{image}', [PersonController::class, 'image'])->name('people.image');
            Route::get('/people/{person}/images/{image}/download', [PersonController::class, 'image'])->defaults('download', true)->name('people.images.download');
            Route::get('/people/{person}/images/{image}/poster', [PersonController::class, 'imagePoster'])->name('people.images.poster');
            Route::post('/people/{person}/images/unlock', [PersonController::class, 'authenticateImages'])->middleware('throttle:8,1')->name('people.images.unlock');
            Route::delete('/people/{person}/images/{image}', [PersonController::class, 'destroyImage'])->name('people.images.destroy');
            Route::get('/avatar-models/{group}/file', [AvatarModelController::class, 'stream'])->name('avatar-models.stream');
        } elseif ($department === 'fm') {
            Route::get('/', [FileManagerController::class, 'dashboard'])->name('dashboard');
            Route::get('/archive/files', [DashboardController::class, 'archiveFiles'])->name('archive.files');
            Route::get('/files/{file:uuid}/preview', [FileController::class, 'preview'])->name('files.preview');
            Route::get('/files/{file:uuid}/stream', [FileController::class, 'stream'])->name('files.stream');
            Route::get('/files/{file:uuid}/download', [FileController::class, 'download'])->name('files.download');
            Route::get('/files/{file:uuid}/video', [FileController::class, 'videoRendition'])->name('files.video');
            Route::get('/files/{file:uuid}/poster', [FileController::class, 'videoPoster'])->name('files.video-poster');
            Route::get('/files/{file:uuid}/archive-entry', [FileController::class, 'archiveEntry'])->name('files.archive-entry');
            Route::get('/uploads', [ManualUploadController::class, 'create'])->name('uploads.create');

            Route::get('/person-media', [FileManagerController::class, 'personMediaIndex'])->name('person-media.index');
            Route::get('/person-media/{person}', [FileManagerController::class, 'personMedia'])->name('person-media.show');
            Route::post('/person-media/{person}/unlock', [PersonController::class, 'authenticateImages'])
                ->middleware('throttle:8,1')
                ->name('person-media.unlock');
            Route::get('/person-media/{person}/images/{image}', [FileManagerController::class, 'personMediaFile'])->name('person-media.image');
            Route::get('/person-media/{person}/images/{image}/download', [FileManagerController::class, 'personMediaFile'])->defaults('download', true)->name('person-media.download');
            Route::get('/person-media/{person}/images/{image}/poster', [PersonController::class, 'imagePoster'])->name('person-media.poster');

            Route::prefix('/shell')->name('shell.')->group(function (): void {
                Route::get('/directory', [VaultShellController::class, 'index'])->name('directory');
                Route::get('/preview', [VaultShellController::class, 'preview'])->name('preview');
                Route::get('/stream', [VaultShellController::class, 'stream'])->name('stream');
                Route::get('/download', [VaultShellController::class, 'download'])->name('download');
                Route::delete('/file', [VaultShellController::class, 'destroy'])->name('file.destroy');
                Route::post('/directory', [VaultShellController::class, 'makeDirectory'])->name('directory.create');
                Route::post('/copy', [VaultShellController::class, 'copy'])->name('copy');
                Route::post('/move', [VaultShellController::class, 'move'])->name('move');
            });
        } else {
            Route::view('/', 'departments.dashboard', [
                'department' => $department,
                'departmentName' => $departmentName,
            ])->name('dashboard');
        }
        Route::get('/profile/photo', [StaffProfilePhotoController::class, 'department'])->name('profile.photo');
        Route::view('/profile', 'departments.profile')->name('profile');
        Route::get('/reports', ReportWorkspace::class)->name('reports.index');
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    });
}

Route::middleware(['auth:web,admin', 'security.presence'])->group(function (): void {
    Route::get('/agency-reports/staff/{user}/photo', [ReportMediaController::class, 'commenterPhoto'])
        ->whereNumber('user')
        ->name('reports.commenter-photo');
    Route::get('/agency-reports/{report}/media/{media}', ReportMediaController::class)
        ->whereNumber('report')
        ->whereNumber('media')
        ->name('reports.media');
});

Route::middleware(['auth:web,admin', 'security.presence', 'throttle:2000,1'])->prefix('/upload-transfers')->name('upload-transfers.')->group(function (): void {
    Route::get('/', [UploadTransferController::class, 'index'])->name('index');
    Route::post('/', [UploadTransferController::class, 'initialize'])->name('initialize');
    Route::post('/{token}/chunks/{index}', [UploadTransferController::class, 'storeChunk'])
        ->whereNumber('index')
        ->name('chunk');
    Route::get('/{token}', [UploadTransferController::class, 'status'])->name('status');
    Route::delete('/{token}', [UploadTransferController::class, 'cancel'])->name('cancel');
    Route::post('/{token}/retry', [UploadTransferController::class, 'retry'])->name('retry');
    Route::delete('/{token}/remove', [UploadTransferController::class, 'remove'])->name('remove');
});

Route::middleware(['auth:web,admin', 'admin.workspace', 'security.presence', 'throttle:1000,1'])->prefix('/video-uploads')->name('video-uploads.')->group(function (): void {
    Route::post('/', [VideoUploadController::class, 'initialize'])->name('initialize');
    Route::post('/{videoUpload}/chunks/{index}', [VideoUploadController::class, 'storeChunk'])
        ->whereNumber('index')
        ->name('chunk');
    Route::get('/{videoUpload}', [VideoUploadController::class, 'status'])->name('status');
    Route::delete('/{videoUpload}', [VideoUploadController::class, 'cancel'])->name('cancel');
    Route::post('/{videoUpload}/retry', [VideoUploadController::class, 'retry'])->name('retry');
});

Route::middleware(['auth:web,admin', 'admin.workspace', 'security.presence'])->prefix('/admin')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('auth.logout');
    Route::get('/staff/{user}/photo', [StaffProfilePhotoController::class, 'admin'])
        ->whereNumber('user')
        ->name('admin.staff.photo');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard.index');
    Route::get('/dashboard/snapshot', [DashboardController::class, 'snapshot'])->name('dashboard.snapshot');
    Route::get('/archive/files', [DashboardController::class, 'archiveFiles'])->name('archive.files');
    Route::get('/files/{file:uuid}/preview', [FileController::class, 'preview'])->name('files.preview');
    Route::get('/files/{file:uuid}/stream', [FileController::class, 'stream'])->name('files.stream');
    Route::get('/files/{file:uuid}/download', [FileController::class, 'download'])->name('files.download');
    Route::get('/files/{file:uuid}/video', [FileController::class, 'videoRendition'])->name('files.video');
    Route::get('/files/{file:uuid}/poster', [FileController::class, 'videoPoster'])->name('files.video-poster');
    Route::get('/files/{file:uuid}/archive-entry', [FileController::class, 'archiveEntry'])->name('files.archive-entry');
    Route::view('/calculator', 'calculator.index')->name('calculator.index');

    Route::get('/people', [PersonController::class, 'index'])->name('people.index');
    Route::get('/people/create', [PersonController::class, 'create'])->name('people.create');
    Route::post('/people', [PersonController::class, 'store'])->name('people.store');
    Route::get('/people/{person}', [PersonController::class, 'show'])->name('people.show');
    Route::get('/people/{person}/edit', [PersonController::class, 'edit'])->name('people.edit');
    Route::put('/people/{person}', [PersonController::class, 'update'])->name('people.update');
    Route::get('/people/{person}/images/{image}', [PersonController::class, 'image'])->name('people.image');
    Route::get('/people/{person}/images/{image}/download', [PersonController::class, 'image'])->defaults('download', true)->name('people.images.download');
    Route::get('/people/{person}/images/{image}/poster', [PersonController::class, 'imagePoster'])->name('people.images.poster');
    Route::post('/people/{person}/images/unlock', [PersonController::class, 'authenticateImages'])->middleware('throttle:8,1')->name('people.images.unlock');
    Route::delete('/people/{person}/images/{image}', [PersonController::class, 'destroyImage'])->name('people.images.destroy');
    Route::get('/people/{person}/linkable-cases', [PersonController::class, 'linkableCases'])->name('people.cases.search');
    Route::post('/people/{person}/cases', [PersonController::class, 'attachCase'])->name('people.cases.attach');
    Route::delete('/people/{person}/cases/{case}', [PersonController::class, 'detachCase'])->name('people.cases.detach');

    Route::get('/cases', [InvestigationCaseController::class, 'index'])->name('cases.index');
    Route::get('/cases/create', [InvestigationCaseController::class, 'create'])->name('cases.create');
    Route::post('/cases', [InvestigationCaseController::class, 'store'])->name('cases.store');
    Route::get('/cases/{caseFile}', [InvestigationCaseController::class, 'show'])->name('cases.show');
    Route::get('/cases/{caseFile}/edit', [InvestigationCaseController::class, 'edit'])->name('cases.edit');
    Route::put('/cases/{caseFile}', [InvestigationCaseController::class, 'update'])->name('cases.update');
    Route::delete('/cases/{caseFile}', [InvestigationCaseController::class, 'destroy'])->name('cases.destroy');
    Route::get('/cases/{caseFile}/images/{image}', [InvestigationCaseController::class, 'image'])->name('cases.image');
    Route::get('/cases/{caseFile}/images/{image}/download', [InvestigationCaseController::class, 'image'])->defaults('download', true)->name('cases.images.download');
    Route::get('/cases/{caseFile}/images/{image}/poster', [InvestigationCaseController::class, 'imagePoster'])->name('cases.images.poster');
    Route::delete('/cases/{caseFile}/images/{image}', [InvestigationCaseController::class, 'destroyImage'])->name('cases.images.destroy');

    Route::get('/avatar-models', [AvatarModelController::class, 'index'])->name('avatar-models.index');
    Route::post('/avatar-models/{group}', [AvatarModelController::class, 'update'])->name('avatar-models.update');
    Route::get('/avatar-models/{group}/file', [AvatarModelController::class, 'stream'])->name('avatar-models.stream');
    Route::get('/uploads', [ManualUploadController::class, 'create'])->name('uploads.create');

    Route::prefix('/shell')->name('shell.')->group(function (): void {
        Route::get('/directory', [VaultShellController::class, 'index'])->name('directory');
        Route::get('/preview', [VaultShellController::class, 'preview'])->name('preview');
        Route::get('/stream', [VaultShellController::class, 'stream'])->name('stream');
        Route::get('/download', [VaultShellController::class, 'download'])->name('download');
        Route::delete('/file', [VaultShellController::class, 'destroy'])->name('file.destroy');
        Route::post('/directory', [VaultShellController::class, 'makeDirectory'])->name('directory.create');
        Route::post('/copy', [VaultShellController::class, 'copy'])->name('copy');
        Route::post('/move', [VaultShellController::class, 'move'])->name('move');
    });

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings/upload-key', [SettingsController::class, 'updateUploadKey'])->name('settings.key.reset');
    Route::post('/settings/upload-policy', [SettingsController::class, 'updateUploadPolicy'])->name('settings.upload-policy.update');
    Route::post('/settings/password', [SettingsController::class, 'updatePassword'])->name('settings.password.update');
    Route::post('/settings/person-image-pin', [SettingsController::class, 'updateImagePin'])->name('settings.person-image-pin.update');
    Route::middleware('auth:admin')->group(function (): void {
        Route::get('/mail', MailCenter::class)->name('admin.mail.index');
        Route::get('/mail/attachments/{attachment}', [AdminEmailAttachmentController::class, 'download'])
            ->whereNumber('attachment')
            ->name('admin.mail.attachments.download');
    });
    Route::get('/staff', StaffManagement::class)->name('admin.staff.index');
    Route::get('/reports', ReportWorkspace::class)->name('admin.reports.index');
    Route::get('/reports/manage', ReportManagement::class)->name('admin.reports.manage');
    Route::get('/staff/{user}/{section}', StaffProfile::class)
        ->whereIn('section', ['authentication-history', 'activity'])
        ->whereNumber('user')
        ->name('admin.staff.history');
    Route::get('/staff/{user}', StaffProfile::class)
        ->whereNumber('user')
        ->name('admin.staff.show');
    Route::get('/departments', DepartmentManagement::class)->name('admin.departments.index');
    Route::get('/departments/{department}/staff', DepartmentStaffDirectory::class)
        ->whereNumber('department')
        ->name('admin.departments.staff');
    Route::middleware('security.read')->prefix('/auth-security')->name('admin.security.')->group(function (): void {
        Route::get('/two-factor', TwoFactorSettings::class)->middleware('auth:admin')->name('two-factor');
        Route::get('/', AuthSecurityDashboard::class)->name('index');
        Route::get('/authentication-history', AuthHistory::class)->name('auth-history');
        Route::get('/authentication-history/{authEvent}', [AuthSecurityController::class, 'authEvent'])->whereNumber('authEvent')->name('auth-event');
        Route::get('/activity-logs', ActivityLogIndex::class)->name('activity');
        Route::get('/activity-logs/{activityLog}', [AuthSecurityController::class, 'activityLog'])->whereNumber('activityLog')->name('activity-detail');
        Route::get('/brute-force', BruteForceIndex::class)->name('brute-force');
        Route::get('/brute-force/{bruteForceRecord}', [AuthSecurityController::class, 'bruteForce'])->whereNumber('bruteForceRecord')->name('brute-force-detail');
        Route::get('/blacklisted-ips', BlacklistedIpIndex::class)->name('blacklisted-ips');
        Route::get('/whitelisted-ips', WhitelistedIpIndex::class)->name('whitelisted-ips');
    });
});

Route::middleware('guest:web')->get('/{department:code}/login', function (Department $department) {
    return view('auth.department-login', [
        'department' => $department->code,
        'departmentName' => $department->name,
    ]);
});

Route::middleware('auth:web')->group(function (): void {
    Route::get('/{department:code}', function (Department $department) {
        abort_unless(auth('web')->user()->department === $department->code, 403);

        return view('departments.dashboard', [
            'department' => $department->code,
            'departmentName' => $department->name,
        ]);
    });

    Route::get('/{department:code}/profile', function (Department $department) {
        abort_unless(auth('web')->user()->department === $department->code, 403);

        return view('departments.profile');
    });

    Route::get('/{department:code}/profile/photo', [StaffProfilePhotoController::class, 'department']);
    Route::post('/{department:code}/logout', function (Department $department, Request $request, LoginController $login) {
        abort_unless(auth('web')->user()->department === $department->code, 403);

        return $login->destroy($request);
    });
});
