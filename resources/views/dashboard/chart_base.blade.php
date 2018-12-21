{{--<!-- 引入在线资源 -->--}}
{{--<script src="https://gw.alipayobjects.com/os/antv/pkg/_antv.g2-3.2.7/dist/g2.min.js"></script>--}}
{{--<script src="https://gw.alipayobjects.com/os/antv/pkg/_antv.data-set-0.8.9/dist/data-set.min.js"></script>--}}


{{--<script src="https://cdn.bootcss.com/echarts/4.1.0.rc2/echarts.min.js"></script>--}}
{{--<script src="https://file.easy.mall-to.com/js/walden.js"></script>--}}

<div class="small">
    {{--<div class="glyphicon glyphicon-info-sign"></div>--}}
    {{--统计数据截止到上一日<br>--}}
    {{--<div class="glyphicon glyphicon-info-sign"></div>--}}
    {{--微信订阅用户:关注了公众号的用户<br>--}}
    {{--<div class="glyphicon glyphicon-info-sign"></div>--}}
    {{--微信系统累计用户:在微信平台使用过本系统服务的用户--}}
    @foreach($helps as $help)
        <div class="glyphicon glyphicon-info-sign"></div>
        {{$help}}<br>
    @endforeach
</div>

@if(!empty($subjects))
    <div class="form-group margin-r-5">
        <label>主体:</label>
        <select id="subject_uuid" name="subject_uuid" class="form-control">
            @foreach($subjects as $key=>$value)
                <option value="{{$key}}">{{$value}}</option>
            @endforeach
        </select>
    </div>
@endif


<script>
    $(document).ready(function () {
        if (window.localStorage) {
            const localStorage = window.localStorage;
            // localStorge可用
            let uuid = localStorage.getItem("subject_uuid");
            // console.log(uuid);
            if (uuid != undefined) {
                $("#subject_uuid option[value='" + uuid + "']").prop("selected", "selected");
            } else {
                $("#subject_uuid option[value='1001']").prop("selected", "selected");
            }


            $('#subject_uuid').on('change', function (event) {
                // console.log(this.value);

                // 获取本地存储对象
                // 存储
                localStorage.setItem("subject_uuid", this.value);

                // let url = funcUrl("subject_uuid", this.value);
                // $.pjax({url: url, container: '#pjax-container'});
                $.pjax.reload('#pjax-container')
            });
        } else {
            // localStorge不可用
            alert("请使用最新版的chrome浏览器");
        }


    });

</script>
