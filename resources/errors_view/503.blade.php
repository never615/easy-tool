@extends('tooL_errors::error_base')

@section('title','503 Service Unavailable')

@section('content')
    <div class="container">
        <!-- Jumbotron -->
        <div class="jumbotron">
            <h1><i class="fa fa-exclamation-triangle orange"></i> 503 系统维护中</h1>
            <a href="javascript:document.location.reload(true);"
               class="btn btn-default btn-lg text-center"><span
                    class="green">刷新重试</span></a>
        </div>
    </div>
@endsection
