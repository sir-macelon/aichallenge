drop procedure if exists generate_matchup;
delimiter $$
create procedure generate_matchup()
begin

select min(players) into @min_players from map;

select count(distinct s.user_id)
into @max_players
from submission s
inner join user u
    on u.user_id = s.user_id
where s.status = 40 and s.latest = 1;

if @min_players <= @max_players then

set @init_mu = 25.0;
set @init_beta = @init_mu / 6;
set @twiceBetaSq = 2 * pow(@init_beta, 2);

-- Step 1: select the seed player

select s.user_id, s.submission_id, s.mu, s.sigma
into @seed_id, @submission_id, @mu, @sigma
from submission s
where s.latest = 1 and s.status = 40
-- this selects the user that was least recently used player for a seed
-- from both the game and matchup tables
order by ( select max(matchup_id)
           from matchup m
           where m.seed_id = s.user_id ) asc,
         ( select max(game_id)
           from game g
           where g.seed_id = s.user_id ) asc,
         s.user_id asc
limit 1;

-- Step 2: select the map

select m.map_id, m.players
into @map_id, @players
from map m
left outer join (
    select map_id, max(g.game_id) as max_game_id, count(*) as game_count
    from game g
    inner join game_player gp
        on g.game_id = gp.game_id
    where g.seed_id = @seed_id
    group by g.map_id
) games
    on m.map_id = games.map_id
left outer join (
    select map_id, max(g.game_id) as max_all_game_id, count(*) as all_game_count
    from game g
    inner join game_player gp
        on g.game_id = gp.game_id
    group by g.map_id
) all_games
    on m.map_id = all_games.map_id
left outer join (
    select map_id, max(m.matchup_id) as max_matchup_id, count(*) as matchup_count
    from matchup m
    inner join matchup_player mp
        on m.matchup_id = mp.matchup_id
    group by m.map_id
) matchups
    on m.map_id = matchups.map_id
where m.players <= @max_players
order by game_count, priority, max_game_id,
         matchup_count, max_matchup_id,
         all_game_count, max_all_game_id,
         map_id
limit 1;

-- Step 2.5: setup matchup and player info for following queries

insert into matchup (seed_id, map_id, worker_id)
values (@seed_id, @map_id, -1);

set @matchup_id = last_insert_id();

insert into matchup_player (matchup_id, user_id, submission_id, player_id, mu, sigma)
values (@matchup_id, @seed_id, @submission_id, -1, @mu, @sigma);

-- select @matchup_id, @seed_id, @submission_id, @map_id, @players;

-- Step 3: select opponents 1 at a time

-- create temp table to hold user_ids not to match
--   due to repeat games, maps or player_counts
drop temporary table if exists temp_unavailable;
create temporary table temp_unavailable (
 user_id int(11) NOT NULL
);
insert into temp_unavailable (user_id) values (@seed_id);

set @last_user_id = -1;
set @cur_user_id = @seed_id;
set @use_limits = 1;
set @abort = 0;
set @player_count = 1;

while @abort = 0 and @player_count < @players do
    -- select @matchup_id as matchup_id, @last_user_id as cur_user,
    --        @use_limits as limits, @abort as abort,
    --        @player_count as player_count, @players as total;
    set @last_user_id = @cur_user_id;
    if @use_limits = 1 then
        -- don't match player that played with anyone matched so far
        -- in the last 5 games
        insert into temp_unavailable
        select gp1.user_id
        from game_player gp1
        inner join game_player gp2
            on gp1.game_id = gp2.game_id
        where gp2.user_id = @last_user_id
        and gp1.game_id in (
            select game_id from (
                -- mysql does not allow limits in subqueries
                -- wrapping in it a dummy select is a work around
                select game_id
                from game_player
                where user_id = @last_user_id
                order by game_id desc
                limit 5
            ) latest_games
        );
    end if;

    set @last_user_id = -1; -- used to ensure an opponent was selected

    -- pick the closest 100 available submissions (limited for speed)
    -- and then rank by a match_quality approximation
    --   the approximation is not the true trueskill match_quality,
    --   but will be in the same order as if we calculated it
    select s.user_id, s.submission_id, s.mu, s.sigma ,
    @c := (@twiceBetaSq + pow(mp.sigma,2) + pow(s.sigma,2)) as c,
    exp(sum(ln(
        SQRT(@twiceBetaSq / @c) * EXP(-(pow(mp.mu - s.mu, 2) / (2 * @c)))
    ))) as match_quality
    into @last_user_id, @last_submission_id, @last_mu, @last_sigma, @c, @match_quality
    from matchup_player mp,
    (
        select s.user_id, s.submission_id, s.mu, s.sigma,
        @mu_avg = (select avg(mu) from matchup_player where matchup_id = @matchup_id)
        from submission s
        inner join user u
            on u.user_id = s.user_id

        where s.latest = 1 and status = 40

        -- this order by causes a filesort, but I don't see a way around it
        -- limiting to 100 saves us from doing extra trueskill calculations
        order by abs(s.mu - @mu_avg)
        limit 100
    ) s
    left outer join temp_unavailable tu
        on s.user_id = tu.user_id
    where mp.matchup_id = @matchup_id
    and tu.user_id is null
    group by s.user_id, s.submission_id, s.mu, s.sigma
    order by match_quality desc
    limit 1;

    if @last_user_id = -1 then
        if @use_limits = 0 then
            set @abort = 1;
        end if;
        delete from temp_unavailable;
        insert into temp_unavailable select user_id from matchup_player where matchup_id = @matchup_id;
        set @use_limits = 0;
    else
        insert into matchup_player (matchup_id, user_id, submission_id, player_id, mu, sigma)
        values (@matchup_id, @last_user_id, @last_submission_id, -1, @last_mu, @last_sigma);
        insert into temp_unavailable (user_id) values (@last_user_id);
        set @player_count = @player_count + 1;
        set @cur_user_id = @last_user_id;
    end if;

end while;

if @abort = 1 then

    delete from matchup_player where matchup_id = @matchup_id;
    delete from matchup where matchup_id = @matchup_id;

else

-- Step 4: put players into map positions

set @player_count = 0;

while @player_count < @players do

    select mp.user_id, id.player_id
    into @pos_user_id, @pos_player_id
    from matchup_player mp
    inner join (
        select user_id
        from matchup_player mp
        where matchup_id = @matchup_id
        and player_id = -1
    ) avail
        on mp.user_id = avail.user_id,
    (
        select @row := @row + 1 as player_id
        from matchup_player,
        (select @row := -1) id
        where matchup_id = @matchup_id
    ) id
    where mp.matchup_id = @matchup_id
    and id.player_id not in (
        select player_id
        from matchup_player mp
        where matchup_id = @matchup_id
        and player_id != -1
    )
    order by (mp.user_id = @seed_id) desc,
    (
        select max(g.game_id)
        from game g
        inner join game_player gp
            on g.game_id = gp.game_id
        where g.map_id = @map_id
        and gp.user_id = mp.user_id
        and gp.player_id = id.player_id
    ) asc
    limit 1;

    update matchup_player
    set player_id = @pos_player_id
    where matchup_id = @matchup_id
    and user_id = @pos_user_id;

    set @player_count = @player_count + 1;

end while;

-- turn matchup on
update matchup
set worker_id = null
where matchup_id = @matchup_id;

-- select @max_players, @min_players;

-- return new matchup id
select @matchup_id as matchup_id;

end if;

end if;

end$$
delimiter ;
