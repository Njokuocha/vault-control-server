@extends($isAdmin ? 'layouts.app' : 'layouts.department')

@section('page_title', 'Case Analysis Portal')
@section('page_label', 'CASE ANALYSIS')

@if ($isAdmin)
    @section('content')
        @include('case-analysis.portal')
    @endsection
@else
    @section('department_content')
        @include('case-analysis.portal')
    @endsection
@endif
