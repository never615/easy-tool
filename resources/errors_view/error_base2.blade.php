<!DOCTYPE html>
<html lang="{{ config('app.locale') }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{--    <meta name="description" content=@yield('title')">--}}

    <title>错误提示</title>
    {{--    <title>@yield('title')</title>--}}


    <link rel="stylesheet"
          href="{{ admin_asset("vendor/laravel-admin/AdminLTE/bootstrap/css/bootstrap.min.css") }}">
    <!-- Font Awesome -->
    <link rel="stylesheet"
          href="{{ admin_asset("vendor/laravel-admin/font-awesome/css/font-awesome.min.css") }}">

    {{--    <link href="https://cdn.bootcss.com/bootstrap/3.3.7/css/bootstrap.min.css" rel="stylesheet"--}}
    {{--          type="text/css">--}}
    {{--    <link href="https://cdn.bootcss.com/font-awesome/4.7.0/css/font-awesome.min.css" rel="stylesheet"--}}
    {{--          type="text/css">--}}


    <style>
        /* Error Page Inline Styles */
        body {
            padding-top: 20px;
        }

        /* Layout */
        .jumbotron {
            font-size: 21px;
            font-weight: 200;
            line-height: 2.1428571435;
            color: inherit;
            padding: 10px 0px;
        }

        /* Everything but the jumbotron gets side spacing for mobile-first views */
        .masthead, .body-content {
            padding-left: 15px;
            padding-right: 15px;
        }

        /* Main marketing message and sign up button */
        .jumbotron {
            text-align: center;
            background-color: transparent;
        }

        .jumbotron .btn {
            font-size: 21px;
            padding: 14px 24px;
        }

        /* Colors */
        .green {
            color: #5cb85c;
        }

        .orange {
            color: #f0ad4e;
        }

        .red {
            color: #d9534f;
        }
    </style>
    <script type="text/javascript">
        function loadDomain() {
            var display = document.getElementById("display-domain");

            display.innerHTML = document.domain;
        }
    </script>
    <title></title>
</head>
<body onload="javascript:loadDomain();">
<!-- Error Page Content -->
@section('content')
    <div class="container">
        <div class="container">
            <!-- Jumbotron -->
            <div class="jumbotron">
                <h1><i class="fa fa-frown-o red"></i> @yield('title')</h1>
                <p class="lead">@yield('desc')</p>
                {{--@include('errors.button')--}}
            </div>
        </div>
        <div class="container">
            <div class="body-content">
                <div class="row">
                    <div class="col-md-6">
                        <h2>发生了什么?</h2>
                        <p>{{isset($exception)?$exception->getMessage():""}}</p>
                        <p class="lead">@yield("error_msg")</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

@show
<!-- End Error Page Content -->
<!--Scripts-->
<script src="{{admin_asset("vendor/laravel-admin/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js") }}"></script>
<script src="{{admin_asset("vendor/laravel-admin/AdminLTE/bootstrap/js/bootstrap.min.js") }}"></script>

{{--        <script src="https://cdn.bootcss.com/jquery/3.2.1/jquery.min.js"></script>--}}
{{--        <script src="https://cdn.bootcss.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>--}}
{{--<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>--}}
{{--<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>--}}
</body>
</html>
