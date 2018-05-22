@extends('errors.error_base')

@section('title','502 Bad Gateway')

@section('content')
    <div class="container">
        <!-- Jumbotron -->
        <div class="jumbotron">
            <h1><i class="fa fa-bolt orange"></i> 502 Bad Gateway</h1>
            <p class="lead">The web server is returning an unexpected networking error for <em><span id="display-domain"></span></em>.</p>
            <a href="javascript:document.location.reload(true);" class="btn btn-default btn-lg text-center"><span class="green">Try This Page Again</span></a>
        </div>
    </div>
    <div class="container">
        <div class="body-content">
            <div class="row">
                <div class="col-md-6">
                    <h2>What happened?</h2>
                    <p class="lead">A 502 error status implies that that the server received an invalid response from an upstream server it accessed to fulfill the request.</p>
                </div>
                <div class="col-md-6">
                    <h2>What can I do?</h2>
                    <p class="lead">If you're a site visitor</p>
                    <p><a onclick=javascript:checkSite();>Check to see if this website down for everyone or just you.</a>
                        <script type="text/javascript">
                            function checkSite(){
                                var currentSite = window.location.hostname;
                                window.location = "http://isup.me/" + currentSite;
                            }
                        </script></p>
                    <p>Also, clearing your browser cache and refreshing the page may clear this issue. If the problem persists and you need immediate assistance, please send us an email instead.</p>
                    <p class="lead">If you're the site owner</p>
                    <p>Clearing your browser cache and refreshing the page may clear this issue. If the problem persists and you need immediate assistance, please contact your website provider.</p>
                </div>
            </div>
        </div>
    </div>

@endsection